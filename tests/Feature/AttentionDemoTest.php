<?php

namespace Tests\Feature;

use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Demo\AttentionDemoService;
use App\Services\Demo\DemoDatabaseGuard;
use App\Services\Planning\StudyAvailabilityService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyRecoveryPreviewService;
use App\Services\Planning\StudyReplanningPersistenceService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttentionDemoTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->travelTo(CarbonImmutable::parse('2026-03-27T06:00:00Z'));
        $this->beforeApplicationDestroyed(function (): void {
            $this->app['env'] = 'testing';
            $this->truncateTablesForAllConnections();
        });
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        PlanningPreference::factory()->for($owner)->create(['min_session_minutes' => 30, 'max_session_minutes' => 60,
            'preferred_break_minutes' => 15, 'max_daily_study_minutes' => 540, 'max_weekly_study_minutes' => 4000]);
        $local = CarbonImmutable::now($owner->timezone);
        foreach ([[1, '09:00:00', '09:45:00'], [2, '14:00:00', '15:00:00']] as [$offset, $start, $end]) {
            StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => $local->addDays($offset)->dayOfWeekIso,
                'starts_at' => $start, 'ends_at' => $end]);
        }

        return $owner;
    }

    private function runDemo(User $owner, string $key, string $step = 'prepare', bool $dry = false): array
    {
        $db = app(DemoDatabaseGuard::class)->inspect(false);

        return app(AttentionDemoService::class)->run($owner, $owner->email, $key, $step, $dry, $db['database'], $db['server_uuid']);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $snapshot[$table] = DB::table($table)->get()->toArray();
        }

        return $snapshot;
    }

    public function test_dry_run_is_select_only_and_produces_no_fake_ids_or_token(): void
    {
        $owner = $this->owner();
        $before = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = $this->runDemo($owner, 'C', dry: true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('for update', strtolower($query['query']));
        }
        $this->assertTrue($result['ready']);
        $this->assertNull($result['task_id']);
        $this->assertNull($result['proposed_records']['task_id']);
        $this->assertStringNotContainsString('preview_token', json_encode($result));
        $this->assertEquals($before, $this->snapshot());
    }

    public static function transfers(): array
    {
        return [['B'], ['C'], ['D'], ['E'], ['H']];
    }

    #[DataProvider('transfers')]
    public function test_real_planner_prepares_valid_source_and_owned_deadline_change_produces_requested_case(string $key): void
    {
        $owner = $this->owner();
        $settings = [$owner->planningPreference->getAttributes(), $owner->studyAvailabilityWindows()->get()->toArray()];
        $prepared = $this->runDemo($owner, $key);
        $this->assertTrue($prepared['written']);
        $source = StudySession::findOrFail($prepared['sessions'][0]['id']);
        $period = $prepared['manifest']['period'];
        $task = Task::findOrFail($prepared['task_id']);
        $before = $source->getAttributes();
        $valid = app(StudyReplanningPersistenceService::class)->preview($owner, ...[...$period, $task->id]);
        $this->assertSame('valid', $valid['session_results'][0]['classification']);
        $triggered = $this->runDemo($owner, $key, 'trigger');
        $this->assertSame($before, $source->refresh()->getAttributes());
        $review = app(StudyReplanningPersistenceService::class)->preview($owner, ...[...$period, $task->id]);
        $row = $review['session_results'][0];
        $duration = $prepared['manifest']['duration_seconds'];
        $total = array_sum(array_map(fn ($r) => $r['end'] - $r['start'], $row['replacements']));
        $this->assertSame('requires_replanning', $row['classification']);
        $this->assertSame($duration - $total, $row['untransferred_seconds']);
        if (in_array($key, ['B', 'H'], true)) {
            $this->assertCount(1, $row['replacements']);
            $this->assertSame($duration, $total);
        } elseif ($key === 'C') {
            $this->assertGreaterThanOrEqual(2, count($row['replacements']));
            $this->assertSame($duration, $total);
        } elseif ($key === 'D') {
            $this->assertGreaterThan(0, $total);
            $this->assertLessThan($duration, $total);
        } else {
            $this->assertSame(0, $total);
        }
        $this->assertSame($settings, [$owner->planningPreference->getAttributes(), $owner->studyAvailabilityWindows()->get()->toArray()]);
        $this->assertSame('replan', $triggered['automatic_suggestion']['kind']);
        $this->actingAs($owner);
        if ($key === 'H') {
            $this->runDemo($owner, $key, 'stale');
            $this->postJson('/api/planning/replanning/confirm', ['preview_token' => $review['preview_token'], 'accept_partial' => true,
                'accept_unplaced_cancellation' => true, 'accept_split_replacements' => true])->assertConflict();
            $this->assertSame($before, $source->refresh()->getAttributes());
        } elseif ($key === 'C') {
            $this->postJson('/api/planning/replanning/confirm', ['preview_token' => $review['preview_token']])->assertUnprocessable()
                ->assertJsonValidationErrors('accept_split_replacements');
            $this->assertSame($before, $source->refresh()->getAttributes());
        } elseif ($key === 'E') {
            $this->postJson('/api/planning/replanning/confirm', ['preview_token' => $review['preview_token'], 'accept_partial' => true])
                ->assertUnprocessable()->assertJsonValidationErrors('accept_unplaced_cancellation');
        }
        if ($key !== 'H') {
            $this->postJson('/api/planning/replanning/confirm', ['preview_token' => $review['preview_token'],
                'accept_partial' => true, 'accept_unplaced_cancellation' => true, 'accept_split_replacements' => true])->assertOk();
            $this->assertSame($key === 'E' ? 'cancelled' : 'rescheduled', $source->refresh()->status->value);
            $this->assertSame(count($row['replacements']), $owner->studySessions()->where('rescheduled_from_session_id', $source->id)->count());
            $this->assertNull($source->actual_minutes);
        }

    }

    public function test_unknown_outcome_uses_real_detector_and_repeat_preserves_user_results_and_other_data(): void
    {
        $owner = $this->owner();
        $legacy = Task::factory()->for($owner)->create(['title' => '[DEMO-PLANNING] preserved', 'estimated_minutes' => 60]);
        $legacyBefore = $legacy->refresh()->getAttributes();
        $result = $this->runDemo($owner, 'A');
        $this->assertSame('decision_required', $result['automatic_suggestion']['kind']);
        $session = StudySession::findOrFail($result['sessions'][0]['id']);
        $this->assertSame('planned', $session->status->value);
        $review = $owner->recoverySuggestions()->where('status', 'pending')->sole()->review;
        $this->assertContains($session->id, array_column($review['unknown_session_decisions'], 'id'));
        $blocked = $this->runDemo($owner, 'B');
        $this->assertFalse($blocked['written']);
        $this->assertFalse($blocked['ready']);
        $this->assertSame([$result['task_id']], $blocked['blocked_by_demo_task_ids']);
        app(TaskService::class)->update($owner, Task::findOrFail($result['task_id']), ['title' => 'Перейменований демонстраційний запис']);
        $this->assertSame([$result['task_id']], $this->runDemo($owner, 'B')['blocked_by_demo_task_ids']);

        app(StudySessionLifecycleService::class)->complete($owner, (string) $session->id, 19);
        app(TaskService::class)->complete($owner, Task::findOrFail($result['task_id']));
        $before = $this->snapshot();
        $again = $this->runDemo($owner, 'A');
        $this->assertFalse($again['written']);
        $this->assertSame(19, $again['sessions'][0]['actual_minutes']);
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame($legacyBefore, $legacy->refresh()->getAttributes());
    }

    public function test_new_plan_and_recovery_have_real_remaining_effort_without_auto_sessions(): void
    {
        foreach (['F', 'G'] as $key) {
            $owner = $this->owner();
            $result = $this->runDemo($owner, $key);
            $this->assertSame('create_plan', $result['automatic_suggestion']['kind']);
            if ($key === 'F') {
                $this->assertCount(0, $result['sessions']);
                $this->assertGreaterThanOrEqual(4, count($result['manual_proposed_sessions']));
            } else {
                $this->assertSame(30, $result['sessions'][0]['actual_minutes']);
                $this->assertSame('completed', $result['sessions'][0]['status']);
                $this->assertSame(5400, $result['manual_plan_summary']['requested_seconds']);
                $before = $result['sessions'];
                $this->assertSame($before, $this->runDemo($owner, 'G', dry: true)['sessions']);
                $original = StudySession::findOrFail($result['sessions'][0]['id']);
                $attributes = $original->getAttributes();
                $preview = app(StudyRecoveryPreviewService::class)->preview($owner,
                    ...[...$result['manifest']['period'], $result['task_id']]);
                app(StudyPlanPersistenceService::class)->confirm($owner,
                    $preview['study_plan']['preview_token'], true);
                $this->assertSame($attributes, $original->refresh()->getAttributes());
                $this->assertSame('pending', Task::findOrFail($result['task_id'])->status->value);

            }
        }
    }

    public function test_exact_owner_database_identity_and_noninteractive_opt_in_are_required(): void
    {
        $owner = $this->owner();
        $before = $this->snapshot();
        $this->artisan('planning:demo-seed', ['--user' => 'id:'.$owner->id, '--attention' => 'A', '--expect-email' => $owner->email])->assertFailed();
        $this->artisan('planning:demo-seed', ['--user' => 'id:'.$owner->id, '--attention' => 'A', '--expect-email' => 'foreign@email.com', '--dry-run' => true])->assertFailed();
        try {
            app(AttentionDemoService::class)->run($owner, $owner->email, 'A', 'prepare', false, 'wrong', 'wrong');
            $this->fail('Wrong database accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('--database', $e->getMessage());
        }
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_unavailable_conditions_and_manual_changes_are_not_repaired_or_overwritten(): void
    {
        $owner = $this->owner();
        $prepared = $this->runDemo($owner, 'B');
        // A legitimate account edit in this disposable test removes the earlier replacement window.
        $window = $owner->studyAvailabilityWindows()->orderBy('id')->firstOrFail();
        app(StudyAvailabilityService::class)->delete($owner, $window);
        $before = $this->snapshot();
        try {
            $this->runDemo($owner, 'B', 'trigger');
            $this->fail('Changed conditions were silently accepted');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('відкочено', $exception->getMessage());
        }
        $this->assertEquals($before, $this->snapshot());
        $this->assertNull(Task::findOrFail($prepared['task_id'])->deadline_at);
        $empty = User::factory()->create(['timezone' => 'UTC']);
        $result = $this->runDemo($empty, 'F');
        $this->assertFalse($result['ready']);
        $this->assertFalse($result['written']);
        $this->assertSame(0, $empty->tasks()->count());
    }
}
