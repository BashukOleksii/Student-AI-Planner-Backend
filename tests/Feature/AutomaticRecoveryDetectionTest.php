<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\RecoverySourceStateService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithLearningRecovery;
use Tests\TestCase;

#[Group('mysql')]
#[Group('recovery')]
class AutomaticRecoveryDetectionTest extends TestCase
{
    use DatabaseTruncation, InteractsWithLearningRecovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeRecovery();
    }

    public function test_scheduler_is_bounded_separate_from_reminders_and_command_never_applies_a_plan(): void
    {
        $f = $this->fixture();
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'learning:detect-recovery'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--limit=10 --days=7', $event->command);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertCount(1, array_filter($events, fn ($event) => str_contains($event->command ?? '', 'reminders:dispatch-due')));
        $before = $this->business();
        $this->artisan('learning:detect-recovery', ['--limit' => '1', '--days' => '7', '--user' => (string) $f['owner']->id])
            ->expectsOutput(json_encode(['selected' => 1, 'created' => 1, 'unchanged' => 0, 'no_need' => 0, 'limited' => 0, 'failed' => 0]))->assertExitCode(0);
        $this->assertSame($before, $this->business());
        $this->assertSame('create_plan', $this->pending($f['owner'])->kind);
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_command_bounds_are_rejected_without_creating_reviews(array $options): void
    {
        $this->artisan('learning:detect-recovery', $options)->assertExitCode(2);
        $this->assertDatabaseCount('recovery_suggestions', 0);
        $this->assertDatabaseCount('recovery_scan_states', 0);
    }

    public static function invalidOptions(): array
    {
        return [[['--limit' => '0']], [['--limit' => '101']], [['--days' => '32']], [['--days' => '1.5']], [['--user' => '-1']]];
    }

    public function test_bounded_scan_rotates_owners_and_never_repeats_an_unchanged_active_need(): void
    {
        $f = $this->fixture();
        $others = User::factory()->count(2)->create(['timezone' => 'UTC']);
        foreach ($others as $owner) {
            Task::factory()->for($owner)->create(['estimated_minutes' => null]);
        }
        $ids = [$f['owner']->id, ...$others->modelKeys()];
        foreach ($ids as $index => $id) {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00Z')->addSeconds($index));
            $this->assertSame(1, $f['service']->scan(1)['selected']);
            $this->assertDatabaseHas('recovery_scan_states', ['user_id' => $id]);
            $this->assertDatabaseCount('recovery_scan_states', $index + 1);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:03Z'));
        $this->assertSame(1, $f['service']->scan(1)['unchanged']);
        $this->assertDatabaseCount('recovery_suggestions', 3);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_large_account_is_not_truncated_and_invalidates_its_old_active_review(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        Task::factory()->count(200)->for($f['owner'])->create(['estimated_minutes' => null]);
        $before = $this->business();
        $this->getJson('/api/planning/recovery/diagnostics?start_date=2026-10-19&end_date=2026-10-25')
            ->assertUnprocessable()->assertJsonPath('code', 'analysis_limit_exceeded');
        $this->assertSame(1, $f['service']->scan(1)['limited']);
        $this->assertSame('invalidated', $row->fresh()->status);
        $this->assertDatabaseCount('recovery_suggestions', 1);
        $this->assertSame($before, $this->business());
    }

    public function test_fingerprint_ignores_clock_ticks_but_availability_changes_make_the_review_stale(): void
    {
        $f = $this->fixture();
        $sources = app(RecoverySourceStateService::class);
        $before = $sources->capture($f['owner'], '2026-10-19', '2026-10-25')['source_hash'];
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:01Z'));
        $this->assertSame($before, $sources->capture($f['owner'], '2026-10-19', '2026-10-25')['source_hash']);
        $f['owner']->studyAvailabilityWindows()->where('day_of_week', 1)->update(['ends_at' => '16:00:00']);
        $path = '/api/planning/recovery-suggestions/'.$row->id;
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'invalidated')->assertJsonPath('data.actionable', false);
        $this->assertSame('pending', $row->fresh()->status, 'GET must not save lifecycle metadata.');
        $this->postJson($path.'/confirm')->assertConflict()->assertJsonPath('code', 'suggestion_stale');
        $this->assertSame('invalidated', $row->fresh()->status);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_partial_new_allocation_requires_explicit_acceptance_and_reports_remaining_work(): void
    {
        $f = $this->fixture();
        $f['task']->update(['deadline_at' => '2026-10-19 09:30:00']);
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $this->assertSame('create_plan', $row->kind);
        $this->assertSame(['accept_partial'], $row->review['required_confirmation_fields']);
        $this->assertSame(1800, $row->review['new_work_plan']['summary']['unscheduled_seconds']);
        $path = '/api/planning/recovery-suggestions/'.$row->id.'/confirm';
        $this->postJson($path)->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        $this->assertDatabaseCount('study_sessions', 0);
        $this->postJson($path, ['accept_partial' => true])->assertOk()->assertJsonPath('data.status', 'applied');
        $this->assertDatabaseCount('study_sessions', 1);
        $this->assertSame('2026-10-19 09:30:00', StudySession::firstOrFail()->getRawOriginal('ends_at'));
        $this->assertSame(60, $f['task']->fresh()->estimated_minutes);
    }

    public function test_valid_reserved_work_does_not_create_unnecessary_recovery(): void
    {
        $f = $this->fixture();
        StudySession::factory()->for($f['task'])->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        $before = $this->business();
        $this->assertSame('no_need', $f['service']->detect($f['owner']->id));
        $this->assertSame('unchanged', $f['service']->detect($f['owner']->id));
        $this->assertDatabaseCount('recovery_suggestions', 0);
        $this->assertSame($before, $this->business());
    }
}
