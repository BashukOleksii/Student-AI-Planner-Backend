<?php

namespace Tests\Feature;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;
use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningCandidateEvaluationService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\PlanningWorkSelectionService;
use App\Services\Planning\StudyPlanningService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReschedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\StudySessionLifecycleRulesTest;

class StudySessionLifecycleTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    #[DataProvider('actions')]
    public function test_authentication_and_owned_lookup_precede_validation(string $action): void
    {
        $this->postJson('/api/study-sessions/999/'.$action, ['user_id' => 5])->assertUnauthorized();
        [$owner, , $source] = $this->fixture();
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create());
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, $action), ['user_id' => 5])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->postJson('/api/study-sessions/999/'.$action)->assertNotFound();
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame('/api/study-sessions/'.$source->id.'/'.$action, route('study-sessions.'.$action, $source->id, false));
    }

    public static function actions(): array
    {
        return [['complete'], ['miss'], ['cancel'], ['reschedule']];
    }

    #[DataProvider('completionEffort')]
    public function test_completion_preserves_history_and_uses_actual_first_accounting(?int $actual): void
    {
        [$user, $task, $source] = $this->fixture();
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
        $source->update(['subtask_id' => $child->id, 'ends_at' => '2026-10-19 10:00:01']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:30:00.987654Z'));
        $snapshot = $this->snapshot(['study_sessions']);
        $data = $this->postJson($this->url($source, 'complete'), $actual === null ? [] : ['actual_minutes' => $actual])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-19T10:30:00Z')->json('data');
        $this->assertSame($actual, $data['actual_minutes']);
        $this->assertSame(3601, $data['duration_seconds']);
        $this->assertSame('2026-10-19T09:00:00Z', $data['starts_at']);
        $this->assertSame($child->id, $data['subtask_id']);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('rescheduled_from_session_id', $data);
        $this->assertSame($snapshot, $this->snapshot(['study_sessions']));
        $effort = app(PlanningWorkSelectionService::class)->select($user, $task->id, $child->id)['work_items'][0]['effort'];
        $this->assertSame($actual === null ? 3601 : $actual * 60, $effort['completed_credit_seconds']);
        $this->assertSame(0, $effort['future_reserved_seconds']);
        $this->assertSame([['start' => strtotime('2026-10-19T09:00:00Z'), 'end' => strtotime('2026-10-19T10:00:01Z')]],
            app(PlanningContextService::class)->load($user, '2026-10-19', '2026-10-19')['workload_sessions']);
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
        $this->assertSame(TaskStatus::Pending, $child->fresh()->status);
        $this->getJson('/api/study-sessions/'.$source->id)->assertOk()->assertExactJson(['data' => $data]);
    }

    public static function completionEffort(): array
    {
        return [[null], [45]];
    }

    #[DataProvider('timeBoundaries')]
    public function test_full_precision_lifecycle_boundaries(string $action, string $now, int $status): void
    {
        [, , $source] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse($now));
        $before = $this->snapshot();
        $this->postJson($this->url($source, $action))->assertStatus($status);
        if ($status === 409) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function timeBoundaries(): array
    {
        return [
            ['complete', '2026-10-19T08:59:59.999999Z', 409], ['complete', '2026-10-19T09:00:00Z', 200],
            ['complete', '2026-10-19T09:00:00.000001Z', 200], ['complete', '2026-10-20T11:00:00Z', 200],
            ['miss', '2026-10-19T08:00:00Z', 409], ['miss', '2026-10-19T09:30:00Z', 409],
            ['miss', '2026-10-19T09:59:59.999999Z', 409], ['miss', '2026-10-19T10:00:00Z', 200],
            ['cancel', '2026-10-19T08:59:59.999999Z', 200], ['cancel', '2026-10-19T09:00:00Z', 409],
            ['cancel', '2026-10-19T09:00:00.000001Z', 409], ['cancel', '2026-10-19T09:30:00Z', 409],
        ];
    }

    #[DataProvider('terminalActions')]
    public function test_all_terminal_states_reject_every_action_without_writes(string $status, string $action): void
    {
        [, , $source] = $this->fixture();
        $source->update(['status' => $status, 'completed_at' => $status === 'completed' ? '2026-10-19 08:00:00' : null,
            'actual_minutes' => $status === 'completed' ? 15 : null]);
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, $action), $action === 'reschedule' ? $this->replacement() : [])->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function terminalActions(): iterable
    {
        foreach (['completed', 'missed', 'cancelled', 'rescheduled'] as $status) {
            foreach (['complete', 'miss', 'cancel', 'reschedule'] as $action) {
                yield $status.' '.$action => [$status, $action];
            }
        }
    }

    #[DataProvider('invalidMinutes')]
    public function test_completion_http_rejects_non_integer_and_explicit_null(mixed $value): void
    {
        [, , $source] = $this->fixture();
        $snapshot = $this->snapshot();
        $this->call('POST', $this->url($source, 'complete'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['actual_minutes' => $value], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))->assertUnprocessable()->assertJsonValidationErrors('actual_minutes');
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function invalidMinutes(): array
    {
        return [[null], [0], [-1], [65536], [1.0], ['1'], [true], [false], [[]], [new \stdClass], [''], [' 45 ']];
    }

    #[DataProvider('actions')]
    public function test_arbitrary_fields_are_rejected_even_when_null(string $action): void
    {
        [, , $source] = $this->fixture();
        $fields = array_fill_keys(['user_id', 'task_id', 'subtask_id', 'status', 'completed_at', 'rescheduled_from_session_id', 'reminders', 'unknown'], null);
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, $action), [...($action === 'reschedule' ? $this->replacement() : []), ...$fields])
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($fields));
        $this->assertSame($snapshot, $this->snapshot());
    }

    #[DataProvider('historicalActions')]
    public function test_valid_owned_deleted_work_history_can_transition(string $action): void
    {
        [, $task, $source] = $this->fixture();
        $child = Subtask::factory()->for($task)->create();
        $source->update(['subtask_id' => $child->id]);
        $child->delete();
        $task->delete();
        $this->travelTo(CarbonImmutable::parse($action === 'cancel' ? '2026-10-19T08:00:00Z' : '2026-10-19T10:00:00Z'));
        $snapshot = $this->snapshot(['study_sessions']);
        $this->postJson($this->url($source, $action))->assertOk()->assertJsonPath('data.task_id', $task->id)->assertJsonPath('data.subtask_id', $child->id);
        $this->assertSame($snapshot, $this->snapshot(['study_sessions']));
    }

    public static function historicalActions(): array
    {
        return [['complete'], ['miss'], ['cancel']];
    }

    #[DataProvider('releaseActions')]
    public function test_planning_accounting_free_time_and_workload_after_transitions(string $action): void
    {
        [$user, $task, $source] = $this->fixture();
        $before = app(PlanningWorkSelectionService::class)->select($user, $task->id)['work_items'][0]['effort'];
        $this->assertSame(3600, $before['future_reserved_seconds']);
        $this->assertSame(3600, $before['unallocated_work_seconds']);
        if ($action === 'miss') {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        }
        $this->postJson($this->url($source, $action), $action === 'reschedule' ? $this->replacement('11:00:00', '11:30:00') : [])->assertSuccessful();
        $effort = app(PlanningWorkSelectionService::class)->select($user, $task->id)['work_items'][0]['effort'];
        $this->assertSame(0, $effort['completed_credit_seconds']);
        $this->assertSame($action === 'reschedule' ? 1800 : 0, $effort['future_reserved_seconds']);
        $this->assertSame($action === 'reschedule' ? 5400 : 7200, $effort['unallocated_work_seconds']);
        $context = app(PlanningContextService::class)->load($user, '2026-10-19', '2026-10-19');
        $this->assertCount($action === 'reschedule' ? 1 : 0, $context['workload_sessions']);
        $old = ['start' => strtotime('2026-10-19T09:00:00Z'), 'end' => strtotime('2026-10-19T10:00:00Z')];
        $this->assertSame([], app(PlanningCandidateEvaluationService::class)->evaluate($user, $old, $task->id));
        $plan = app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19', $task->id);
        $this->assertSame($effort['unallocated_work_seconds'], $plan['summary']['requested_seconds']);
        $this->assertTrue($plan['summary']['fully_allocated']);
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
    }

    public static function releaseActions(): array
    {
        return [['miss'], ['cancel'], ['reschedule']];
    }

    public function test_reschedule_history_exact_fields_partial_summary_and_valid_chain(): void
    {
        [$user, $task, $source] = $this->fixture();
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120, 'deadline_at' => '2026-10-19 12:00:00']);
        $source->update(['subtask_id' => $child->id]);
        $snapshot = $this->snapshot(['study_sessions']);
        $data = $this->postJson($this->url($source, 'reschedule'), $this->replacement('11:00:01', '11:30:02'))->assertCreated()->json('data');
        $this->assertSame(['original_duration_seconds' => 3600, 'replacement_duration_seconds' => 1801,
            'not_transferred_seconds' => 1799, 'unallocated_work_seconds' => 5399], $data['summary']);
        $this->assertSame('rescheduled', $data['original']['status']);
        $this->assertSame('2026-10-19T09:00:00Z', $data['original']['starts_at']);
        $successor = StudySession::findOrFail($data['replacement']['id']);
        $this->assertSame($source->id, $successor->rescheduled_from_session_id);
        $this->assertSame($child->id, $successor->subtask_id);
        $this->assertSame($task->id, $successor->task_id);
        $this->assertSame($user->id, $successor->user_id);
        $this->assertNull($successor->actual_minutes);
        $this->assertNull($successor->completed_at);
        $next = $this->postJson($this->url($successor, 'reschedule'), $this->replacement('11:29:59', '12:00:00'))
            ->assertCreated()->json('data');
        $this->assertSame($successor->id, StudySession::findOrFail($next['replacement']['id'])->rescheduled_from_session_id);
        $this->assertSame($snapshot, $this->snapshot(['study_sessions']));
        $this->assertDatabaseCount('study_sessions', 3);
        $this->postJson($this->url($source, 'reschedule'), $this->replacement())->assertConflict();
    }

    public function test_source_does_not_block_its_own_replacement_and_full_transfer_is_reported(): void
    {
        [$user, , $source] = $this->fixture();
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 60, 'preferred_break_minutes' => 15]);
        $this->postJson($this->url($source, 'reschedule'), $this->replacement('09:00:00', '10:00:00'))->assertCreated()
            ->assertJsonPath('data.summary.not_transferred_seconds', 0)->assertJsonPath('data.summary.unallocated_work_seconds', 3600);
        $this->assertCount(1, app(PlanningContextService::class)->load($user, '2026-10-19', '2026-10-19')['workload_sessions']);
    }

    #[DataProvider('conflictingState')]
    public function test_rescheduling_revalidates_current_work_and_all_constraints(string $change): void
    {
        [$user, $task, $source] = $this->fixture();
        switch ($change) {
            case 'lesson': Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 11:00:00', 'ends_at' => '2026-10-19 12:00:00']);
                break;
            case 'session': StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 11:00:00', 'ends_at' => '2026-10-19 12:00:00']);
                break;
            case 'minimum': PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 61]);
                break;
            case 'maximum': PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 59]);
                break;
            case 'availability': $user->studyAvailabilityWindows()->delete();
                break;
            case 'break':
                PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 15]);
                Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 10:45:01']);
                break;
            case 'daily':
                PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 60]);
                StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 13:00:00', 'ends_at' => '2026-10-19 14:00:00']);
                break;
            case 'weekly':
                PlanningPreference::factory()->for($user)->create(['max_weekly_study_minutes' => 60]);
                StudySession::factory()->for($task)->create(['starts_at' => '2026-10-20 13:00:00', 'ends_at' => '2026-10-20 14:00:00']);
                break;
            case 'deadline': $task->update(['deadline_at' => '2026-10-19 11:59:59']);
                break;
            case 'parent_deadline':
                $task->update(['deadline_at' => '2026-10-19 11:59:59']);
                $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120, 'deadline_at' => '2026-10-19 13:00:00']);
                $source->update(['subtask_id' => $child->id]);
                break;
            case 'deleted': $task->delete();
                break;
            case 'completed': $task->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 08:00:00']);
                break;
            case 'child_deleted':
            case 'child_completed':
                $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
                $source->update(['subtask_id' => $child->id]);
                if ($change === 'child_deleted') {
                    $child->delete();
                } else {
                    $child->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 08:00:00']);
                } break;
            case 'decomposed': Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
                break;
            case 'estimate': $task->update(['estimated_minutes' => 59]);
                break;
            case 'missing_estimate': $task->update(['estimated_minutes' => null]);
                break;
            case 'completed_effort':
                StudySession::factory()->for($task)->create(['status' => StudySessionStatus::Completed,
                    'starts_at' => '2026-10-18 09:00:00', 'ends_at' => '2026-10-18 10:00:00', 'completed_at' => '2026-10-18 10:00:00', 'actual_minutes' => 120]);
                break;
            case 'reservation': StudySession::factory()->for($task)->create(['starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:30:00']);
                break;
        }
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, 'reschedule'), $this->replacement())->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function conflictingState(): array
    {
        return array_map(fn ($kind) => [$kind], ['lesson', 'session', 'minimum', 'maximum', 'availability', 'break', 'daily', 'weekly',
            'deadline', 'parent_deadline', 'deleted', 'completed', 'child_deleted', 'child_completed', 'decomposed', 'estimate', 'missing_estimate', 'completed_effort', 'reservation']);
    }

    public function test_exact_break_deadline_duration_and_local_workload_boundaries_pass(): void
    {
        [$user, $task, $source] = $this->fixture();
        $user->update(['timezone' => 'Europe/Kyiv']);
        $task->update(['deadline_at' => '2026-10-19 12:00:00']);
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 60, 'max_session_minutes' => 60,
            'preferred_break_minutes' => 15, 'max_daily_study_minutes' => 60, 'max_weekly_study_minutes' => 120]);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 10:45:00']);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-10-18 22:00:00', 'ends_at' => '2026-10-18 23:00:00',
            'status' => StudySessionStatus::Completed, 'completed_at' => '2026-10-18 23:00:00', 'actual_minutes' => 1]);
        // Completed scheduled hour is on the same local Monday: daily limit must reject despite one actual minute.
        $this->postJson($this->url($source, 'reschedule'), $this->replacement())->assertConflict();
        $user->planningPreference()->update(['max_daily_study_minutes' => 120]);
        $this->postJson($this->url($source, 'reschedule'), $this->replacement())->assertCreated();
    }

    #[DataProvider('badTimestamps')]
    public function test_rescheduling_strict_timestamp_inputs_are_422_without_writes(mixed $value): void
    {
        [, , $source] = $this->fixture();
        $before = $this->snapshot();
        $this->postJson($this->url($source, 'reschedule'), [...$this->replacement(), 'starts_at' => $value])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->assertSame($before, $this->snapshot());
    }

    public static function badTimestamps(): array
    {
        return StudySessionLifecycleRulesTest::badTimestamps();
    }

    #[DataProvider('replacementBoundaries')]
    public function test_invalid_interval_and_future_or_duration_protection(string $start, string $end, int $status): void
    {
        [, , $source] = $this->fixture();
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, 'reschedule'), $this->replacement($start, $end))->assertStatus($status);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function replacementBoundaries(): array
    {
        return [['11:00:00', '11:00:00', 422], ['11:00:00', '10:59:59', 422], ['08:00:00', '09:00:00', 409],
            ['07:00:00', '08:00:00', 409], ['11:00:00', '12:00:01', 409]];
    }

    #[DataProvider('actions')]
    public function test_scheduled_session_reminders_are_cancelled_and_history_and_other_targets_preserved(string $action): void
    {
        [$user, $task, $source] = $this->fixture();
        $absolute = Reminder::factory()->for($user)->create(['study_session_id' => $source->id]);
        $relative = Reminder::factory()->for($user)->create(['study_session_id' => $source->id,
            'anchor' => ReminderAnchor::SessionStart, 'offset_minutes' => -15, 'trigger_at' => '2026-10-19 08:45:00']);
        $sent = Reminder::factory()->for($user)->create(['study_session_id' => $source->id, 'status' => ReminderStatus::Sent, 'sent_at' => '2026-10-19 07:00:00']);
        $cancelled = Reminder::factory()->for($user)->create(['study_session_id' => $source->id, 'status' => ReminderStatus::Cancelled]);
        $taskReminder = Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $general = Reminder::factory()->for($user)->create();
        $other = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 09:01:00']);
        $otherReminder = Reminder::factory()->for($user)->create(['study_session_id' => $other->id]);
        $preserved = [$sent, $cancelled, $taskReminder, $general, $otherReminder];
        $before = array_map(fn ($reminder) => $reminder->fresh()->getRawOriginal(), $preserved);
        if (in_array($action, ['complete', 'miss'], true)) {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        }
        $this->postJson($this->url($source, $action), $action === 'reschedule' ? $this->replacement() : [])->assertSuccessful();
        foreach ([$absolute, $relative] as $reminder) {
            $raw = $reminder->getRawOriginal();
            $fresh = $reminder->fresh();
            $this->assertSame(ReminderStatus::Cancelled, $fresh->status);
            $this->assertNull($fresh->sent_at);
            foreach (['task_id', 'subtask_id', 'study_session_id', 'message', 'trigger_at', 'anchor', 'offset_minutes', 'created_at'] as $field) {
                $this->assertSame($raw[$field], $fresh->getRawOriginal($field));
            }
        }
        $this->assertSame($before, array_map(fn ($reminder) => $reminder->fresh()->getRawOriginal(), $preserved));
        $this->assertDatabaseCount('reminders', 7);
    }

    #[DataProvider('unsafeReminders')]
    public function test_unsafe_reminder_dependencies_fail_generically_and_atomically(string $action, string $kind): void
    {
        [$user, $task, $source] = $this->fixture();
        Reminder::factory()->for($user)->create(['study_session_id' => $source->id]);
        $attributes = ['study_session_id' => $source->id];
        match ($kind) {
            'foreign' => $attributes['user_id'] = User::factory()->create()->id,
            'multiple' => $attributes['task_id'] = $task->id,
            'anchor' => $attributes = [...$attributes, 'anchor' => ReminderAnchor::TaskDeadline, 'offset_minutes' => -15],
        };
        Reminder::factory()->for($user)->create($attributes);
        if (in_array($action, ['complete', 'miss'], true)) {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        }
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, $action), $action === 'reschedule' ? $this->replacement() : [])->assertStatus(500)
            ->assertExactJson(['message' => 'The study plan could not be processed.']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function unsafeReminders(): iterable
    {
        foreach (self::actions() as [$action]) {
            foreach (['foreign', 'multiple', 'anchor'] as $kind) {
                yield [$action, $kind];
            }
        }
    }

    #[DataProvider('corruptSources')]
    public function test_corrupted_work_and_history_fail_closed(string $kind): void
    {
        [, $task, $source] = $this->fixture();
        switch ($kind) {
            case 'foreign_task': $source->update(['task_id' => Task::factory()->create()->id]);
                break;
            case 'sibling_child': $source->update(['subtask_id' => Subtask::factory()->create()->id]);
                break;
            case 'self': $source->update(['rescheduled_from_session_id' => $source->id]);
                break;
            case 'wrong_status':
            case 'foreign_predecessor':
            case 'other_task':
                $parent = StudySession::factory()->for($task)->create(['status' => $kind === 'wrong_status' ? 'planned' : 'rescheduled']);
                if ($kind === 'foreign_predecessor') {
                    $foreign = Task::factory()->create();
                    $parent->update(['user_id' => $foreign->user_id, 'task_id' => $foreign->id]);
                }
                if ($kind === 'other_task') {
                    $parent->update(['task_id' => Task::factory()->for($task->user)->create()->id]);
                }
                $source->update(['rescheduled_from_session_id' => $parent->id]);
                break;
            case 'illegal_successor': StudySession::factory()->for($task)->create(['rescheduled_from_session_id' => $source->id]);
                break;
            case 'cycle':
                $parent = StudySession::factory()->for($task)->create(['status' => 'rescheduled', 'rescheduled_from_session_id' => $source->id]);
                $source->update(['rescheduled_from_session_id' => $parent->id]);
                break;
        }
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, 'reschedule'), $this->replacement())->assertStatus(500)->assertExactJson(['message' => 'The study plan could not be processed.']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function corruptSources(): array
    {
        return array_map(fn ($kind) => [$kind], ['foreign_task', 'sibling_child', 'self', 'wrong_status', 'foreign_predecessor', 'other_task', 'illegal_successor', 'cycle']);
    }

    public function test_failure_after_retiring_predecessor_or_inserting_replacement_rolls_back_every_row(): void
    {
        [$user, , $source] = $this->fixture();
        Reminder::factory()->for($user)->create(['study_session_id' => $source->id]);
        $snapshot = $this->snapshot();
        StudySession::created(fn () => throw new RuntimeException('Injected failure after insert.'));
        try {
            $this->postJson($this->url($source, 'reschedule'), $this->replacement())->assertStatus(500);
        } finally {
            StudySession::flushEventListeners();
        }
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_reminder_write_failure_rolls_back_completion_and_actual_credit(): void
    {
        [$user, , $source] = $this->fixture();
        Reminder::factory()->for($user)->create(['study_session_id' => $source->id]);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        $snapshot = $this->snapshot();
        Reminder::updating(fn () => throw new RuntimeException('Injected reminder failure.'));
        try {
            $this->postJson($this->url($source, 'complete'), ['actual_minutes' => 45])->assertStatus(500);
        } finally {
            Reminder::flushEventListeners();
        }
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_fresh_outermost_boundary_is_required_for_both_mutation_services(): void
    {
        [$user, , $source] = $this->fixture();
        $snapshot = $this->snapshot();
        DB::beginTransaction();
        try {
            foreach ([fn () => app(StudySessionLifecycleService::class)->cancel($user, (string) $source->id),
                fn () => app(StudySessionReschedulingService::class)->reschedule($user, (string) $source->id, ...array_values($this->replacement()))] as $action) {
                try {
                    $action();
                    $this->fail('An inherited transaction was accepted.');
                } catch (LogicException $exception) {
                    $this->assertStringContainsString('outermost', $exception->getMessage());
                }
            }
        } finally {
            DB::rollBack();
        }
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_stage_c_confirmed_sessions_support_lifecycle_and_future_previews(): void
    {
        [$user, $task, $source] = $this->fixture();
        $this->postJson($this->url($source, 'cancel'))->assertOk();
        $service = app(StudyPlanPersistenceService::class);
        $preview = $service->preview($user, '2026-10-19', '2026-10-19', $task->id);
        $saved = $service->confirm($user, $preview['preview_token'])['sessions'][0];
        $this->postJson($this->url($saved, 'reschedule'), $this->replacement('13:00:00', '14:00:00'))->assertCreated();
        $plan = app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19', $task->id);
        $this->assertSame(3600, $plan['summary']['requested_seconds']);
    }

    public function test_null_duration_preferences_allow_one_second_replacement_and_keep_exact_remainder(): void
    {
        [, , $source] = $this->fixture();
        $this->postJson($this->url($source, 'reschedule'), $this->replacement('11:00:00', '11:00:01'))->assertCreated()
            ->assertJsonPath('data.replacement.duration_seconds', 1)->assertJsonPath('data.summary.not_transferred_seconds', 3599)
            ->assertJsonPath('data.summary.unallocated_work_seconds', 7199);
    }

    public function test_positive_maximum_actual_minutes_and_repeated_completion_preserve_original_credit(): void
    {
        [, , $source] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T09:00:00Z'));
        $this->postJson($this->url($source, 'complete'), ['actual_minutes' => 65535])->assertOk()->assertJsonPath('data.actual_minutes', 65535);
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, 'complete'), ['actual_minutes' => 1])->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
    }

    #[DataProvider('elapsedStarts')]
    public function test_running_or_started_source_cannot_reschedule(string $now): void
    {
        [, , $source] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse($now));
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, 'reschedule'), $this->replacement())->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function elapsedStarts(): array
    {
        return [['2026-10-19T09:00:00Z'], ['2026-10-19T09:00:00.000001Z'], ['2026-10-19T09:30:00Z'], ['2026-10-19T10:00:00Z']];
    }

    #[DataProvider('localCalendarCases')]
    public function test_dst_midnight_and_iso_week_boundaries_reuse_current_constraints(string $date, string $start, string $end, int $seconds): void
    {
        [$user, $task, $source] = $this->fixture();
        $user->update(['timezone' => 'Europe/Kyiv']);
        $task->update(['estimated_minutes' => 240]);
        $user->studyAvailabilityWindows()->delete();
        foreach (range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => '00:00:00', 'ends_at' => '24:00:00']);
        }
        $this->travelTo(CarbonImmutable::parse($date.'T00:00:00Z')->subDay());
        $source->update(['starts_at' => CarbonImmutable::parse($start)->subHours(4)->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::parse($start)->subHours(2)->format('Y-m-d H:i:s')]);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 120, 'max_weekly_study_minutes' => 120]);
        $this->postJson($this->url($source, 'reschedule'), ['starts_at' => $start, 'ends_at' => $end])->assertCreated()
            ->assertJsonPath('data.replacement.starts_at', $start)->assertJsonPath('data.replacement.ends_at', $end)
            ->assertJsonPath('data.replacement.duration_seconds', $seconds);
        $this->assertSame(1, count(app(PlanningContextService::class)->load($user, $date, $date)['workload_sessions']));
    }

    public static function localCalendarCases(): array
    {
        return [
            ['2027-03-28', '2027-03-28T00:30:00Z', '2027-03-28T02:30:00Z', 7200],
            ['2026-10-25', '2026-10-25T00:30:00Z', '2026-10-25T02:30:00Z', 7200],
            ['2027-01-04', '2027-01-03T21:30:00Z', '2027-01-03T22:30:00Z', 3600],
        ];
    }

    public function test_server_timezone_and_query_parameters_do_not_change_completion_metadata(): void
    {
        [, , $source] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T09:00:00.123456Z'));
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Tokyo');
            $this->postJson($this->url($source, 'complete').'?actual_minutes=45')->assertOk()
                ->assertJsonPath('data.completed_at', '2026-10-19T09:00:00Z')->assertJsonPath('data.actual_minutes', null)
                ->assertJsonPath('data.starts_at', '2026-10-19T09:00:00Z');
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_clock_advance_during_insert_rolls_back_replacement_source_and_reminders(): void
    {
        [$user, , $source] = $this->fixture();
        Reminder::factory()->for($user)->create(['study_session_id' => $source->id]);
        $snapshot = $this->snapshot();
        StudySession::created(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-19T11:00:00Z')));
        try {
            $this->postJson($this->url($source, 'reschedule'), $this->replacement())->assertConflict();
        } finally {
            StudySession::flushEventListeners();
        }
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_corrupt_other_selected_planning_source_is_not_treated_as_no_capacity(): void
    {
        [$user, $task, $source] = $this->fixture();
        $foreign = Task::factory()->create();
        StudySession::factory()->create(['user_id' => $user->id, 'task_id' => $foreign->id,
            'starts_at' => '2026-10-19 13:00:00', 'ends_at' => '2026-10-19 14:00:00']);
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, 'reschedule'), $this->replacement())->assertStatus(500)
            ->assertExactJson(['message' => 'The study plan could not be processed.']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => 120]);
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        foreach (range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00']);
        }
        $this->actingAs($user);

        return [$user, $task, $source];
    }

    private function url(StudySession $source, string $action): string
    {
        return '/api/study-sessions/'.$source->id.'/'.$action;
    }

    private function replacement(string $start = '11:00:00', string $end = '12:00:00'): array
    {
        return ['starts_at' => '2026-10-19T'.$start.'Z', 'ends_at' => '2026-10-19T'.$end.'Z'];
    }

    private function snapshot(array $except = []): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (! in_array($table, $except, true)) {
                $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
                usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                $result[$table] = $rows;
            }
        }
        ksort($result);

        return $result;
    }
}
