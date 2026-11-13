<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeOwnerPlan;
use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningPreferenceService;
use App\Services\Planning\RecoveryCheckTriggerService;
use App\Services\Planning\RecoverySuggestionService;
use App\Services\Planning\StudyAvailabilityService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PlanningAttentionTest extends TestCase
{
    use DatabaseTruncation;

    private mixed $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13T08:00:00Z'));
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 60]);
        $window = StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        $session = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-16 09:00:00', 'ends_at' => '2026-11-16 10:00:00']);
        $this->actingAs($owner);

        return [$owner, $window, $session];
    }

    private function execute(User $owner): void
    {
        $this->travel(4)->seconds();
        $state = DB::table('recovery_scan_states')->where('user_id', $owner->id)->first();
        (new AnalyzeOwnerPlan($owner->id, $state->queued_token))->handle(app(RecoveryCheckTriggerService::class), app(RecoverySuggestionService::class));
    }

    public function test_trigger_commits_once_and_rollbacks_never_dispatch(): void
    {
        $owner = User::factory()->create();
        DB::beginTransaction();
        app(StudyAvailabilityService::class)->create($owner, ['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('recovery_scan_states', 0);
        DB::beginTransaction();
        foreach ([1, 2, 3] as $day) {
            app(StudyAvailabilityService::class)->create($owner, ['day_of_week' => $day, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        }
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(AnalyzeOwnerPlan::class, 1);
        app(StudyAvailabilityService::class)->create($owner, ['day_of_week' => 4, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        Queue::assertPushed(AnalyzeOwnerPlan::class, 1);
        $this->assertSame(2, DB::table('recovery_scan_states')->where('user_id', $owner->id)->value('requested_version'));
    }

    public function test_nested_rollback_does_not_suppress_a_later_committed_request(): void
    {
        $owner = User::factory()->create();
        DB::beginTransaction();
        DB::beginTransaction();
        app(StudyAvailabilityService::class)->create($owner, ['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        DB::rollBack();
        app(StudyAvailabilityService::class)->create($owner, ['day_of_week' => 2, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(AnalyzeOwnerPlan::class, 1);
        $this->assertDatabaseCount('study_availability_windows', 1);
        $this->assertSame(1, DB::table('recovery_scan_states')->where('user_id', $owner->id)->value('requested_version'));
    }

    public function test_current_owned_attention_disappears_after_fix_and_never_moves_sessions(): void
    {
        [$owner, $window, $session] = $this->fixture();
        $foreign = User::factory()->create(['timezone' => 'UTC']);
        $before = $session->fresh()->getAttributes();
        app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '11:00:00']);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.check_state', 'pending')
            ->assertJsonPath('data.attention_count', 0);
        $this->execute($owner);
        $json = $this->getJson('/api/planning/attention-status?user_id='.$foreign->id)->assertOk()
            ->assertJsonPath('data.most_important_kind', 'replan')->assertJsonPath('data.attention_count', 1)->json('data');
        $this->assertArrayNotHasKey('review', $json);
        $this->assertArrayNotHasKey('preview_token', $json);
        $this->assertArrayNotHasKey('source_hash', $json);
        $this->assertSame($before, $session->fresh()->getAttributes());
        $this->assertSame(0, $foreign->recoverySuggestions()->count());
        $this->actingAs($foreign)->getJson('/api/planning/attention-status')->assertOk()
            ->assertJsonPath('data.attention_count', 0)->assertJsonPath('data.most_important_kind', null);
        $this->actingAs($owner);
        app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '09:00:00']);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.attention_count', 0)
            ->assertJsonPath('data.unverified_suggestion_count', 1)->assertJsonPath('data.check_state', 'pending');
        $this->execute($owner);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.attention_count', 0)
            ->assertJsonPath('data.check_state', 'idle');
        $this->assertSame('invalidated', $owner->recoverySuggestions()->sole()->status);
        $this->assertSame($before, $session->fresh()->getAttributes());
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_job_coalesces_latest_changes_and_suggestion_deduplication(): void
    {
        [$owner, $window] = $this->fixture();
        app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '11:00:00']);
        app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '12:00:00']);
        Queue::assertPushed(AnalyzeOwnerPlan::class, 1);
        $token = DB::table('recovery_scan_states')->where('user_id', $owner->id)->value('queued_token');
        $this->assertGreaterThan(0, app(RecoveryCheckTriggerService::class)->start($owner->id, $token));
        $this->execute($owner);
        (new AnalyzeOwnerPlan($owner->id, $token))->handle(app(RecoveryCheckTriggerService::class), app(RecoverySuggestionService::class));
        app(RecoverySuggestionService::class)->scan(10, 7, $owner->id);
        $this->assertSame(1, $owner->recoverySuggestions()->count());
        $state = DB::table('recovery_scan_states')->where('user_id', $owner->id)->first();
        $this->assertSame($state->requested_version, $state->checked_version);
        $this->assertNull($state->queued_token);
    }

    public function test_dispatch_failure_does_not_undo_preferences_and_scheduler_recovers(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        Queue::shouldReceive('connection')->with('database')->once()->andThrow(new RuntimeException('Unavailable queue'));
        app(PlanningPreferenceService::class)->replace($owner, ['min_session_minutes' => 30, 'max_session_minutes' => 60,
            'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null]);
        $this->assertSame(30, PlanningPreference::findOrFail($owner->id)->min_session_minutes);
        $this->actingAs($owner)->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.check_failed', true);
        $this->artisan('learning:detect-recovery', ['--limit' => '10', '--days' => '7', '--user' => (string) $owner->id])->assertSuccessful();
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.check_state', 'idle')
            ->assertJsonPath('data.check_failed', false);
    }

    public function test_database_queue_delivery_invokes_the_shared_detector_after_commit(): void
    {
        Queue::swap($this->realQueue);
        [$owner, $window, $session] = $this->fixture();
        app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '11:00:00']);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNull(Queue::connection('database')->pop());
        $this->travel(4)->seconds();
        $job = Queue::connection('database')->pop();
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
        $this->assertDatabaseCount('jobs', 0);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.most_important_kind', 'replan');
        $this->assertSame('planned', $session->fresh()->status->value);
    }

    public function test_changes_around_a_running_delivery_are_not_lost(): void
    {
        [$owner, $window] = $this->fixture();
        $this->travel(4)->seconds();
        $trigger = app(RecoveryCheckTriggerService::class);
        $token = DB::table('recovery_scan_states')->where('user_id', $owner->id)->value('queued_token');
        $this->assertSame(0, $trigger->start($owner->id, $token));
        $this->assertNull($trigger->start($owner->id, $token)); // duplicate delivery cannot run concurrently
        app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '12:00:00']);
        app(RecoverySuggestionService::class)->detect($owner->id); // analysis captures the latest owner-locked inputs
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.attention_count', 1);
        app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '09:00:00']);
        Queue::assertPushed(AnalyzeOwnerPlan::class, 2);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.check_state', 'pending')->assertJsonPath('data.attention_count', 0);
        $this->execute($owner);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.check_state', 'idle');
    }

    public function test_analysis_failure_has_an_explicit_status_and_preserves_saved_inputs(): void
    {
        [$owner, $window, $session] = $this->fixture();
        app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '11:00:00']);
        $this->mock(RecoverySuggestionService::class, fn ($mock) => $mock->shouldReceive('detect')->once()->andThrow(new RuntimeException('Broken analysis')));
        $this->execute($owner);
        $this->assertSame('11:00:00', $window->fresh()->starts_at);
        $this->assertSame('planned', $session->fresh()->status->value);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.check_state', 'failed')->assertJsonPath('data.attention_count', 0);
    }

    public function test_information_is_separate_from_warning_and_expired_reviews_are_not_counted(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 60]);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        $this->actingAs($owner);
        $this->execute($owner);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.most_important_kind', 'create_plan')
            ->assertJsonPath('data.attention_count', 0)->assertJsonPath('data.has_informational_recommendations', true);
        $this->travel(16)->minutes();
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.check_state', 'pending')
            ->assertJsonPath('data.has_informational_recommendations', false)->assertJsonPath('data.unverified_suggestion_count', 1);
        app(TaskService::class)->update($owner, $task, ['estimated_minutes' => null]);
        $this->execute($owner);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.most_important_kind', 'warning')->assertJsonPath('data.attention_count', 1);
    }

    public function test_status_requires_auth_and_time_boundary_cannot_be_reported_as_clear(): void
    {
        $this->getJson('/api/planning/attention-status')->assertUnauthorized();
        [$owner, $window, $session] = $this->fixture();
        $this->execute($owner);
        $this->travelTo(CarbonImmutable::parse('2026-11-16T09:01:00Z'));
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.check_state', 'pending');
        app(RecoverySuggestionService::class)->detect($owner->id);
        $this->getJson('/api/planning/attention-status')->assertOk()->assertJsonPath('data.most_important_kind', 'decision_required');
    }
}
