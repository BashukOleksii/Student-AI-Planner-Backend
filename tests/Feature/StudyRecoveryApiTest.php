<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudyRecoveryApiTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-08T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public static function unfinishedStatuses(): array
    {
        return [['pending'], ['missed']];
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_repeated_previews_are_select_only_and_standard_confirmation_creates_new_work_without_retiring_missed_history(string $status): void
    {
        [$user, $task] = $this->fixture(status: $status);
        $user->update(['timezone' => 'Europe/Kyiv']);
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 60]);
        $missed = $this->persistedSession($task, ['subtask_id' => $child->id]);
        $this->persistedSession($task, ['subtask_id' => $child->id]);
        Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $before = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->preview(['task_id' => $task->id, 'subtask_id' => $child->id]);
        $again = $this->preview(['task_id' => $task->id, 'subtask_id' => $child->id]);
        $ordinary = $this->postJson('/api/planning/study-plans/preview', [...$this->parameters(),
            'task_id' => $task->id, 'subtask_id' => $child->id])->assertOk()->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('for update', strtolower($query['query']));
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertNotSame($data['study_plan']['preview_token'], $again['study_plan']['preview_token']);
        $token = $data['study_plan']['preview_token'];
        unset($data['study_plan']['preview_token'], $again['study_plan']['preview_token']);
        unset($ordinary['preview_token']);
        $this->assertSame($data, $again);
        $this->assertSame($ordinary, $data['study_plan']);
        $this->assertSame(['eligible_work_count' => 1, 'missed_session_count' => 2, 'past_planned_session_count' => 0,
            'requires_lifecycle_decision' => false, 'actionable' => true], $data['recovery']['summary']);
        $this->assertSame($missed->id, $data['recovery']['missed_sessions'][0]['study_session_id']);
        $this->assertSame([], $data['recovery']['missed_sessions'][0]['allowed_lifecycle_actions']);
        $this->assertSame([['task_id' => $task->id, 'subtask_id' => $child->id, 'starts_at' => '2026-11-09T07:00:00Z',
            'ends_at' => '2026-11-09T08:00:00Z', 'duration_seconds' => 3600]], $data['study_plan']['sessions']);
        $this->assertSame('2026-11-08T08:15:00Z', $data['study_plan']['expires_at']);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $token])->assertCreated()
            ->assertJsonPath('data.sessions.0.subtask_id', $child->id);
        $new = StudySession::latest('id')->firstOrFail();
        $this->assertNull($new->rescheduled_from_session_id);
        $this->assertSame($before, $this->snapshot([$new->id]));
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $token])->assertConflict();
        $this->assertDatabaseCount('study_sessions', 3);
        $fresh = $this->preview();
        $this->assertSame([], $fresh['study_plan']['sessions']);
        $this->assertArrayNotHasKey('preview_token', $fresh['study_plan']);
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_completed_actual_credit_and_future_reservations_prevent_duplicate_allocation_even_outside_the_horizon(string $status): void
    {
        [, $task] = $this->fixture(120, $status);
        $this->persistedSession($task);
        $completed = $this->persistedSession($task, ['status' => 'completed', 'ends_at' => '2026-11-07 10:30:00',
            'completed_at' => '2026-11-07 10:30:00', 'actual_minutes' => 30]);
        $this->persistedSession($task, ['status' => 'planned', 'starts_at' => '2026-11-10 09:00:00', 'ends_at' => '2026-11-10 10:00:00']);
        $data = $this->preview();
        $this->assertSame(1800, $data['study_plan']['summary']['requested_seconds']);
        $this->assertSame(1800, $data['study_plan']['sessions'][0]['duration_seconds']);
        $before = $this->snapshot();
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['study_plan']['preview_token']])->assertCreated();
        $this->assertSame($before, $this->snapshot([StudySession::latest('id')->firstOrFail()->id]));
        $reserved = $this->preview();
        $this->assertSame(['fully_reserved'], $reserved['recovery']['missed_sessions'][0]['work_reason_codes']);
        $this->assertSame([], $reserved['study_plan']['sessions']);
        $completed->update(['actual_minutes' => 120]);
        $satisfied = $this->preview();
        $this->assertSame(['completed_effort_satisfies_estimate'], $satisfied['recovery']['missed_sessions'][0]['work_reason_codes']);
        $this->assertSame(0, $satisfied['recovery']['summary']['eligible_work_count']);
        $this->assertArrayNotHasKey('preview_token', $satisfied['study_plan']);
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_past_plans_expired_deadlines_and_capacity_are_diagnostic_and_partial_acceptance_remains_explicit(string $status): void
    {
        [$user, $task] = $this->fixture(240, $status);
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 60, 'preferred_break_minutes' => 15,
            'max_daily_study_minutes' => 120, 'max_weekly_study_minutes' => 120]);
        $ended = $this->persistedSession($task, ['status' => 'planned']);
        $inProgress = $this->persistedSession($task, ['status' => 'planned', 'starts_at' => '2026-11-08 07:30:00', 'ends_at' => '2026-11-08 08:30:00']);
        $expired = Task::factory()->for($user)->create(['estimated_minutes' => 60, 'deadline_at' => '2026-11-08 07:00:00', 'status' => $status]);
        $this->persistedSession($expired);
        $before = $this->snapshot();
        $data = $this->preview();
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue($data['recovery']['summary']['requires_lifecycle_decision']);
        $this->assertSame([$ended->id, $inProgress->id], array_column($data['recovery']['past_planned_sessions'], 'study_session_id'));
        $this->assertSame(['complete', 'miss'], $data['recovery']['past_planned_sessions'][0]['allowed_lifecycle_actions']);
        $this->assertSame(['complete'], $data['recovery']['past_planned_sessions'][1]['allowed_lifecycle_actions']);
        $this->assertContains('deadline_expired', $data['recovery']['missed_sessions'][0]['work_reason_codes']);
        $this->assertSame(['stale_planned_session', 'stale_planned_session'], array_column($data['study_plan']['warnings'], 'code'));
        $this->assertFalse($data['study_plan']['summary']['fully_allocated']);
        $this->assertContains('planning_horizon_exhausted', $data['study_plan']['work_results'][0]['reason_codes']);
        $this->assertSame(7200, $data['study_plan']['summary']['allocated_seconds']);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['study_plan']['preview_token']])
            ->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['study_plan']['preview_token'], 'accept_partial' => true])
            ->assertCreated()->assertJsonCount(2, 'data.sessions');
        $this->assertSame('planned', $ended->fresh()->status->value);
        $this->assertSame('planned', $inProgress->fresh()->status->value);
        $user->studyAvailabilityWindows()->delete();
        $empty = $this->preview();
        $this->assertArrayNotHasKey('preview_token', $empty['study_plan']);
        $this->assertContains('no_available_study_time', $empty['study_plan']['work_results'][0]['reason_codes']);
    }

    #[DataProvider('confirmationChanges')]
    public function test_expired_or_conflicting_confirmation_does_not_write_any_sessions(string $change): void
    {
        [$user, $task] = $this->fixture();
        $this->persistedSession($task);
        $data = $this->preview();
        match ($change) {
            'expiry' => $this->travelTo(CarbonImmutable::parse('2026-11-08T08:15:00Z')),
            'deadline' => $task->update(['deadline_at' => '2026-11-08 09:00:00']),
            'completion' => $task->update(['status' => 'completed', 'completed_at' => '2026-11-08 08:00:00']),
            'conflict' => Lesson::factory()->for($user)->create(['starts_at' => '2026-11-09 09:00:00', 'ends_at' => '2026-11-09 10:00:00']),
        };
        $before = $this->snapshot();
        $response = $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['study_plan']['preview_token']]);
        $response->assertStatus($change === 'expiry' ? 422 : 409);
        $this->assertSame($before, $this->snapshot());
    }

    public static function confirmationChanges(): array
    {
        return [['expiry'], ['deadline'], ['completion'], ['conflict']];
    }

    public function test_authentication_validation_and_explicit_work_scope_remain_protected(): void
    {
        $this->postJson('/api/planning/recovery/preview')->assertUnauthorized();
        [$user, $task] = $this->fixture();
        $foreign = Task::factory()->create(['estimated_minutes' => 60]);
        $this->persistedSession($foreign);
        $child = Subtask::factory()->for($foreign)->create(['estimated_minutes' => 60]);
        foreach ([['task_id' => $foreign->id], ['task_id' => $task->id, 'subtask_id' => $child->id]] as $scope) {
            $this->postJson('/api/planning/recovery/preview', [...$this->parameters(), ...$scope])->assertNotFound()
                ->assertExactJson(['message' => 'Not found.']);
        }
        $this->postJson('/api/planning/recovery/preview')->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        foreach (['start_date' => ' 2026-11-09', 'end_date' => '2026-12-10', 'task_id' => '1', 'user_id' => $foreign->user_id] as $field => $value) {
            $this->postJson('/api/planning/recovery/preview', array_replace($this->parameters(), [$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $owned = $this->preview();
        $this->assertSame([], $owned['recovery']['missed_sessions']);
        $this->assertSame($task->id, $owned['study_plan']['sessions'][0]['task_id']);
        $before = $this->snapshot();
        Auth::forgetGuards();
        $this->actingAs($foreign->user, 'web')->postJson('/api/planning/study-plans/confirm',
            ['preview_token' => $owned['study_plan']['preview_token']])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertSame($before, $this->snapshot());
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
        $task->delete();
        $this->postJson('/api/planning/recovery/preview', [...$this->parameters(), 'task_id' => $task->id])->assertNotFound();
        $empty = $this->preview();
        $this->assertSame(0, $empty['recovery']['summary']['eligible_work_count']);
        $this->assertSame([], $empty['study_plan']['sessions']);
        $this->assertSame('/api/planning/recovery/preview', route('planning.recovery.preview', [], false));
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_missing_estimates_and_completed_work_are_excluded_and_corrupt_missed_references_fail_closed(string $status): void
    {
        [, $task] = $this->fixture(status: $status);
        $this->persistedSession($task);
        $task->update(['estimated_minutes' => null]);
        $this->assertSame(['missing_estimate'], $this->preview()['recovery']['missed_sessions'][0]['work_reason_codes']);
        $task->update(['estimated_minutes' => 60, 'status' => 'completed', 'completed_at' => '2026-11-07 10:00:00']);
        $data = $this->preview();
        $this->assertSame(['completed_task'], $data['recovery']['missed_sessions'][0]['work_reason_codes']);
        $this->assertArrayNotHasKey('preview_token', $data['study_plan']);
        $foreignChild = Subtask::factory()->create();
        $this->persistedSession($task, ['subtask_id' => $foreignChild->id]);
        config(['app.debug' => true]);
        $this->postJson('/api/planning/recovery/preview', $this->parameters())->assertStatus(500)
            ->assertExactJson(['message' => 'The study plan could not be processed.']);
    }

    private function fixture(int $estimate = 60, string $status = 'pending'): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => $estimate, 'status' => $status]);
        $this->actingAs($user, 'web');

        return [$user, $task];
    }

    private function parameters(): array
    {
        return ['start_date' => '2026-11-09', 'end_date' => '2026-11-09'];
    }

    private function preview(array $scope = []): array
    {
        return $this->postJson('/api/planning/recovery/preview', [...$this->parameters(), ...$scope])->assertOk()->json('data');
    }

    private function persistedSession(Task $task, array $values = []): StudySession
    {
        return StudySession::factory()->for($task)->create(['status' => 'missed',
            'starts_at' => '2026-11-07 09:00:00', 'ends_at' => '2026-11-07 10:00:00', ...$values]);
    }

    /** Same all-table snapshot approach as StudyPlanApiTest, optionally omitting new rows. */
    private function snapshot(array $excludedSessionIds = []): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $query = DB::table($table);
            if ($table === 'study_sessions') {
                $query->whereNotIn('id', $excludedSessionIds);
            }
            $rows = $query->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $snapshot[$table] = $rows;
        }
        ksort($snapshot);

        return $snapshot;
    }
}
