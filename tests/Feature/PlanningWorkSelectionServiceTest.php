<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningCandidateEvaluationService;
use App\Services\Planning\PlanningWorkSelectionService;
use App\Services\Planning\RemainingEffortService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class PlanningWorkSelectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:20:30.500000Z'));
    }

    public function test_empty_user_and_one_task_return_the_exact_public_contract(): void
    {
        $user = User::factory()->create();
        $this->assertSame(['as_of_utc' => '2026-10-12T10:20:30.500000Z', 'work_items' => [], 'excluded_items' => [], 'warnings' => []], $this->select($user));
        $task = $this->task($user);
        $this->assertSame(['as_of_utc' => '2026-10-12T10:20:30.500000Z',
            'work_items' => [$this->item($task, null, null, [3600, 0, 3600, 0, 3600, 0])], 'excluded_items' => [], 'warnings' => []], $this->select($user));
    }

    #[DataProvider('completionStates')]
    public function test_parent_and_child_statuses_are_independent_and_excluded_without_mutation(bool $parentComplete, bool $childComplete): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['status' => $parentComplete ? TaskStatus::Completed : TaskStatus::Pending]);
        $child = $this->child($task, ['estimated_minutes' => 30, 'status' => $childComplete ? TaskStatus::Completed : TaskStatus::Pending]);
        $before = [$task->refresh()->getAttributes(), $child->refresh()->getAttributes()];
        $parent = $this->item($task, null, null, null);
        $parent['reason_codes'] = ['subtasks_authoritative', ...($parentComplete ? ['completed_task'] : [])];
        $unit = $this->item($task, $child, null, [1800, 0, 1800, 0, 1800, 0]);
        $result = $this->select($user);
        if (! $parentComplete && ! $childComplete) {
            $this->assertSame([$unit], $result['work_items']);
            $this->assertSame([$parent], $result['excluded_items']);
        } else {
            $unit['reason_codes'] = [...($parentComplete ? ['parent_task_completed'] : []), ...($childComplete ? ['completed_subtask'] : [])];
            $this->assertSame([], $result['work_items']);
            $this->assertSame([$parent, $unit], $result['excluded_items']);
        }
        $this->assertSame($before, [$task->refresh()->getAttributes(), $child->refresh()->getAttributes()]);
    }

    public static function completionStates(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    public function test_active_children_override_parent_estimate_and_missing_sibling_is_partially_eligible(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['estimated_minutes' => 600]);
        $valid = $this->child($task, ['estimated_minutes' => 20, 'position' => 3]);
        $missing = $this->child($task, ['estimated_minutes' => null, 'position' => 2]);
        $complete = $this->child($task, ['status' => TaskStatus::Completed, 'position' => 1]);
        $result = $this->select($user);
        $this->assertSame([$this->item($task, $valid, null, [1200, 0, 1200, 0, 1200, 0])], $result['work_items']);
        $this->assertSame(['subtasks_authoritative'], $result['excluded_items'][0]['reason_codes']);
        $this->assertNull($result['excluded_items'][0]['effort']);
        $this->assertSame($complete->id, $result['excluded_items'][1]['subtask_id']);
        $this->assertSame(['completed_subtask'], $result['excluded_items'][1]['reason_codes']);
        $this->assertSame($missing->id, $result['excluded_items'][2]['subtask_id']);
        $this->assertSame(['missing_estimate'], $result['excluded_items'][2]['reason_codes']);
        $this->assertSame($this->metrics([null, 0, null, 0, null, null]), $result['excluded_items'][2]['effort']);
    }

    public function test_only_deleted_children_restore_task_authority_without_reassigning_child_history(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $child = $this->child($task);
        $child->delete();
        $source = $this->studySession($task, StudySessionStatus::Completed, ['subtask_id' => $child->id, 'actual_minutes' => 60]);
        $this->studySession($task, StudySessionStatus::Completed, ['actual_minutes' => 15]);
        $result = $this->select($user);
        $this->assertSame([$this->item($task, null, null, [3600, 900, 2700, 0, 2700, 0])], $result['work_items']);
        $this->assertSame([], $result['excluded_items']);
        $this->assertSame([$this->warning('session_targets_deleted_subtask', $task->id, $child->id, $source->id, 3600)], $result['warnings']);
    }

    public function test_parent_history_and_deleted_child_history_do_not_credit_surviving_children(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['estimated_minutes' => 600]);
        $first = $this->child($task, ['estimated_minutes' => 30, 'position' => 1]);
        $second = $this->child($task, ['estimated_minutes' => 40, 'position' => 2]);
        $deleted = $this->child($task);
        $deleted->delete();
        $parentCredit = $this->studySession($task, StudySessionStatus::Completed, ['actual_minutes' => 120]);
        $parentReservation = $this->studySession($task, StudySessionStatus::Planned);
        $deletedCredit = $this->studySession($task, StudySessionStatus::Completed, ['subtask_id' => $deleted->id, 'actual_minutes' => 120]);
        $deletedReservation = $this->studySession($task, StudySessionStatus::Planned, ['subtask_id' => $deleted->id]);
        $this->studySession($task, StudySessionStatus::Completed, ['subtask_id' => $first->id, 'actual_minutes' => 10]);
        $this->studySession($task, StudySessionStatus::Planned, ['subtask_id' => $second->id, 'ends_at' => '2026-10-12 11:15:00']);
        $result = $this->select($user);
        $this->assertSame([
            $this->item($task, $first, null, [1800, 600, 1200, 0, 1200, 0]),
            $this->item($task, $second, null, [2400, 0, 2400, 900, 1500, 0]),
        ], $result['work_items']);
        $this->assertSame([
            $this->warning('ambiguous_parent_session_attribution', $task->id, null, $parentCredit->id, 7200),
            $this->warning('ambiguous_parent_session_attribution', $task->id, null, $parentReservation->id, 3600),
            $this->warning('session_targets_deleted_subtask', $task->id, $deleted->id, $deletedCredit->id, 7200),
            $this->warning('session_targets_deleted_subtask', $task->id, $deleted->id, $deletedReservation->id, 3600),
        ], $result['warnings']);
    }

    #[DataProvider('sessionStates')]
    public function test_each_status_has_exact_credit_or_reservation_semantics(StudySessionStatus $status, bool $future): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['estimated_minutes' => 120]);
        $values = $status === StudySessionStatus::Completed ? ['actual_minutes' => 15] : [];
        if (! $future) {
            $values += ['starts_at' => '2000-01-01 10:00:00', 'ends_at' => '2000-01-01 11:00:00'];
        }
        $source = $this->studySession($task, $status, $values);
        $result = $this->select($user);
        $credit = $status === StudySessionStatus::Completed ? 900 : 0;
        $reserved = $status === StudySessionStatus::Planned && $future ? 3600 : 0;
        $this->assertSame([$this->item($task, null, null, [7200, $credit, 7200 - $credit, $reserved, 7200 - $credit - $reserved, 0])], $result['work_items']);
        $this->assertSame($status === StudySessionStatus::Planned && ! $future
            ? [$this->warning('stale_planned_session', $task->id, null, $source->id, 3600)] : [], $result['warnings']);
    }

    public static function sessionStates(): array
    {
        $cases = [];
        foreach (StudySessionStatus::cases() as $status) {
            $cases[$status->value.' historical'] = [$status, false];
            $cases[$status->value.' future'] = [$status, true];
        }

        return $cases;
    }

    #[DataProvider('completedCredits')]
    public function test_historical_actual_first_credits_and_second_precision(?int $actual, string $end, int $credit, array $reasons, array $warningCodes): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $this->studySession($task, StudySessionStatus::Completed, ['starts_at' => '2000-01-01 10:00:00', 'ends_at' => $end, 'actual_minutes' => $actual]);
        $result = $this->select($user);
        $record = $reasons === [] ? $result['work_items'][0] : $result['excluded_items'][0];
        $remaining = max(0, 3600 - $credit);
        $this->assertSame($this->metrics([3600, $credit, $remaining, 0, $remaining, 0]), $record['effort']);
        $this->assertSame($reasons, $record['reason_codes'] ?? []);
        $this->assertSame($warningCodes, array_column($result['warnings'], 'code'));
    }

    public static function completedCredits(): array
    {
        return [
            [null, '2000-01-01 10:15:01', 901, [], []],
            [15, '2000-01-01 11:00:00', 900, [], []],
            [45, '2000-01-01 10:15:00', 2700, [], []],
            [null, '2000-01-01 11:00:00', 3600, ['completed_effort_satisfies_estimate'], []],
            [61, '2000-01-01 10:15:00', 3660, ['completed_effort_satisfies_estimate'], ['completed_effort_exceeds_estimate']],
        ];
    }

    public function test_distinct_equal_completed_records_are_additive_and_a_source_row_is_counted_once(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $child = $this->child($task);
        for ($i = 0; $i < 2; $i++) {
            $this->studySession($task, StudySessionStatus::Completed, ['subtask_id' => $child->id, 'actual_minutes' => 15]);
        }
        $result = $this->select($user);
        $this->assertSame($this->metrics([3600, 1800, 1800, 0, 1800, 0]), $result['work_items'][0]['effort']);
    }

    #[DataProvider('reservationAmounts')]
    public function test_reservations_reduce_only_unallocated_effort_and_report_over_reservation(int $minutes, int $unallocated, int $over, array $reasons): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $this->studySession($task, StudySessionStatus::Completed, ['actual_minutes' => 15]);
        $end = CarbonImmutable::parse('2026-10-12 11:00:00', 'UTC')->addMinutes($minutes)->format('Y-m-d H:i:s');
        $this->studySession($task, StudySessionStatus::Planned, ['ends_at' => $end]);
        $result = $this->select($user);
        $record = $reasons === [] ? $result['work_items'][0] : $result['excluded_items'][0];
        $this->assertSame($this->metrics([3600, 900, 2700, $minutes * 60, $unallocated, $over]), $record['effort']);
        $this->assertSame($reasons, $record['reason_codes'] ?? []);
        $this->assertSame($over > 0 ? [$this->warning('future_reservation_exceeds_remaining_work', $task->id, null, null, $over)] : [], $result['warnings']);
    }

    public static function reservationAmounts(): array
    {
        return [[15, 1800, 0, []], [45, 0, 0, ['fully_reserved']], [46, 0, 60, ['fully_reserved']]];
    }

    #[DataProvider('reservationTimes')]
    public function test_future_reservations_use_one_full_precision_clock(string $now, string $start, string $end, bool $future): void
    {
        $this->travelTo(CarbonImmutable::parse($now));
        $user = User::factory()->create();
        $task = $this->task($user, ['estimated_minutes' => 120]);
        $session = $this->studySession($task, StudySessionStatus::Planned, ['starts_at' => $start, 'ends_at' => $end]);
        $result = $this->select($user);
        $seconds = (new DateTimeImmutable($end.'Z'))->getTimestamp() - (new DateTimeImmutable($start.'Z'))->getTimestamp();
        $this->assertSame($this->metrics([7200, 0, 7200, $future ? $seconds : 0, 7200 - ($future ? $seconds : 0), 0]), $result['work_items'][0]['effort']);
        $this->assertSame($future ? [] : [$this->warning('stale_planned_session', $task->id, null, $session->id, $seconds)], $result['warnings']);
        $this->assertSame(StudySessionStatus::Planned, $session->refresh()->status);
        $this->assertSame(CarbonImmutable::parse($now)->utc()->format('Y-m-d\TH:i:s.u\Z'), $result['as_of_utc']);
    }

    public static function reservationTimes(): array
    {
        return [
            ['2026-10-12T10:20:30Z', '2026-10-12 10:20:30', '2026-10-12 11:20:30', true],
            ['2026-10-12T10:20:30.500000Z', '2026-10-12 10:20:30', '2026-10-12 11:20:30', false],
            ['2026-10-12T10:20:30.500000Z', '2026-10-12 10:20:31', '2026-10-12 11:20:31', true],
            ['2026-10-12T10:20:30.500000Z', '2026-10-12 09:00:00', '2026-10-12 11:00:00', false],
            ['2026-10-12T10:20:30.500000Z', '2020-01-01 10:00:00', '2020-01-01 11:00:00', false],
        ];
    }

    #[DataProvider('effectiveDeadlines')]
    public function test_effective_deadline_and_reservation_end_equality(?string $parentDeadline, ?string $childDeadline, ?string $effective, bool $valid): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['deadline_at' => $parentDeadline]);
        $child = $this->child($task, ['estimated_minutes' => 120, 'deadline_at' => $childDeadline]);
        $source = $this->studySession($task, StudySessionStatus::Planned, ['subtask_id' => $child->id]);
        $result = $this->select($user, $task->id, $child->id);
        $this->assertSame([$this->item($task, $child, $effective === null ? null : $this->seconds($effective),
            [7200, 0, 7200, $valid ? 3600 : 0, $valid ? 3600 : 7200, 0])], $result['work_items']);
        $this->assertSame($valid ? [] : [$this->warning('reservation_deadline_exceeded', $task->id, $child->id, $source->id, 3600)], $result['warnings']);
    }

    public static function effectiveDeadlines(): array
    {
        return [
            ['2026-10-12 13:00:00', '2026-10-12 12:00:00', '2026-10-12 12:00:00', true],
            ['2026-10-12 12:00:00', '2026-10-12 13:00:00', '2026-10-12 12:00:00', true],
            ['2026-10-12 11:59:59', '2026-10-12 13:00:00', '2026-10-12 11:59:59', false],
            ['2026-10-12 13:00:00', '2026-10-12 11:59:59', '2026-10-12 11:59:59', false],
            ['2026-10-12 12:00:00', null, '2026-10-12 12:00:00', true],
            [null, '2026-10-12 12:00:00', '2026-10-12 12:00:00', true],
            [null, null, null, true],
        ];
    }

    #[DataProvider('deadlineCutoffs')]
    public function test_expired_deadlines_exclude_new_planning_without_historical_slots(string $now, string $deadline, bool $expired): void
    {
        $this->travelTo(CarbonImmutable::parse($now));
        $user = User::factory()->create();
        $task = $this->task($user, ['deadline_at' => $deadline]);
        $result = $this->select($user);
        $this->assertSame($expired ? [] : [$this->item($task, null, $this->seconds($deadline), [3600, 0, 3600, 0, 3600, 0])], $result['work_items']);
        $this->assertSame($expired ? ['deadline_expired'] : [], $result['excluded_items'][0]['reason_codes'] ?? []);
    }

    public static function deadlineCutoffs(): array
    {
        return [
            ['2026-10-12T10:20:30Z', '2026-10-12 10:20:29', true],
            ['2026-10-12T10:20:30Z', '2026-10-12 10:20:30', true],
            ['2026-10-12T10:20:30.500000Z', '2026-10-12 10:20:30', true],
            ['2026-10-12T10:20:30.500000Z', '2026-10-12 10:20:32', false],
        ];
    }

    public function test_missing_task_estimate_keeps_known_credit_and_reservations_without_defaults(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['estimated_minutes' => null]);
        $this->studySession($task, StudySessionStatus::Completed, ['actual_minutes' => 15]);
        $this->studySession($task, StudySessionStatus::Planned);
        $result = $this->select($user);
        $this->assertSame([], $result['work_items']);
        $this->assertSame($this->metrics([null, 900, null, 3600, null, null]), $result['excluded_items'][0]['effort']);
        $this->assertSame(['missing_estimate'], $result['excluded_items'][0]['reason_codes']);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public function test_completed_task_and_child_reservations_are_diagnostic_only(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['status' => TaskStatus::Completed]);
        $source = $this->studySession($task, StudySessionStatus::Planned);
        $childParent = $this->task($user);
        $child = $this->child($childParent, ['status' => TaskStatus::Completed]);
        $childSource = $this->studySession($childParent, StudySessionStatus::Planned, ['subtask_id' => $child->id]);
        $result = $this->select($user);
        $this->assertSame([], $result['work_items']);
        $this->assertSame(0, $result['excluded_items'][0]['effort']['future_reserved_seconds']);
        $this->assertSame(0, $result['excluded_items'][2]['effort']['future_reserved_seconds']);
        $this->assertSame([
            $this->warning('reservation_targets_completed_work', $task->id, null, $source->id, 3600),
            $this->warning('reservation_targets_completed_work', $childParent->id, $child->id, $childSource->id, 3600),
        ], $result['warnings']);
    }

    public function test_stale_parent_and_deleted_child_sessions_remain_diagnosed_without_reassignment(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $this->child($task);
        $deleted = $this->child($task);
        $deleted->delete();
        $this->studySession($task, StudySessionStatus::Planned, ['starts_at' => '2020-01-01 10:00:00', 'ends_at' => '2020-01-01 11:00:00']);
        $this->studySession($task, StudySessionStatus::Planned, ['subtask_id' => $deleted->id, 'starts_at' => '2020-01-01 10:00:00', 'ends_at' => '2020-01-01 11:00:00']);
        $result = $this->select($user);
        $this->assertSame(['ambiguous_parent_session_attribution', 'stale_planned_session', 'session_targets_deleted_subtask', 'stale_planned_session'], array_column($result['warnings'], 'code'));
        $this->assertSame($this->metrics([3600, 0, 3600, 0, 3600, 0]), $result['work_items'][0]['effort']);
    }

    public function test_order_is_deadline_then_priority_task_id_child_position_and_child_id(): void
    {
        $user = User::factory()->create();
        $nullHigh = $this->task($user, ['priority' => TaskPriority::High]);
        $late = $this->task($user, ['deadline_at' => '2026-10-20 00:00:00', 'priority' => TaskPriority::High]);
        $low = $this->task($user, ['deadline_at' => '2026-10-15 00:00:00', 'priority' => TaskPriority::Low]);
        $normal = $this->task($user, ['deadline_at' => '2026-10-15 00:00:00']);
        $high = $this->task($user, ['deadline_at' => '2026-10-15 00:00:00', 'priority' => TaskPriority::High]);
        $highLaterId = $this->task($user, ['deadline_at' => '2026-10-15 00:00:00', 'priority' => TaskPriority::High]);
        $childA = $this->child($highLaterId, ['position' => 2]);
        $childB = $this->child($highLaterId, ['position' => 1]);
        $childC = $this->child($highLaterId, ['position' => 1]);
        $earlyChild = $this->child($nullHigh, ['deadline_at' => '2026-10-14 00:00:00']);
        $nullChild = $this->child($nullHigh);
        $result = $this->select($user);
        $this->assertSame([
            [$nullHigh->id, $earlyChild->id], [$high->id, null], [$highLaterId->id, $childB->id],
            [$highLaterId->id, $childC->id], [$highLaterId->id, $childA->id], [$normal->id, null], [$low->id, null],
            [$late->id, null], [$nullHigh->id, $nullChild->id],
        ], array_map(fn ($item): array => [$item['task_id'], $item['subtask_id']], $result['work_items']));
        foreach ($result['work_items'] as $item) {
            if ($item['task_id'] === $highLaterId->id) {
                $this->assertSame(3, $item['priority']);
            }
        }
        $this->assertSame($result, $this->select($user));
    }

    public function test_retrieval_order_does_not_control_output_order(): void
    {
        $user = User::factory()->create();
        $first = $this->task($user);
        $second = $this->task($user);
        $this->child($first, ['position' => 2]);
        $this->child($first, ['position' => 1]);
        $this->studySession($first, StudySessionStatus::Completed);
        $this->studySession($second, StudySessionStatus::Completed, ['actual_minutes' => 15]);
        $expected = $this->select($user);
        // Alter only SELECT retrieval order, retaining real Eloquent/MySQL sources.
        Task::addGlobalScope('reverse_selection_test', fn ($query) => $query->orderByDesc('tasks.id'));
        Subtask::addGlobalScope('reverse_selection_test', fn ($query) => $query->orderByDesc('subtasks.id'));
        StudySession::addGlobalScope('reverse_selection_test', fn ($query) => $query->orderByDesc('study_sessions.id'));
        try {
            $this->assertSame($expected, $this->select($user));
        } finally {
            Task::clearBootedModels();
            Subtask::clearBootedModels();
            StudySession::clearBootedModels();
        }
    }

    public function test_explicit_task_and_child_selection_keep_estimate_authority_and_other_reservations_separate(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $first = $this->child($task, ['position' => 1]);
        $second = $this->child($task, ['position' => 2]);
        $this->task($user);
        $this->studySession($task, StudySessionStatus::Planned, ['subtask_id' => $first->id]);
        $whole = $this->select($user, $task->id);
        $this->assertSame([$second->id], array_column($whole['work_items'], 'subtask_id'));
        $this->assertSame(['subtasks_authoritative', 'fully_reserved'], array_map(fn ($item) => $item['reason_codes'][0], $whole['excluded_items']));
        $specific = $this->select($user, $task->id, $second->id);
        $this->assertSame([$this->item($task, $second, null, [3600, 0, 3600, 0, 3600, 0])], $specific['work_items']);
        $this->assertSame([], $specific['excluded_items']);
        $this->assertSame([], $specific['warnings']);
    }

    #[DataProvider('invalidTargets')]
    public function test_explicit_invalid_targets_have_generic_owned_not_found_behavior(string $scenario): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $taskId = $task->id;
        $childId = null;
        if ($scenario === 'missing task') {
            $taskId = PHP_INT_MAX;
        } elseif ($scenario === 'foreign task') {
            $taskId = $this->task(User::factory()->create(), ['title' => 'private title'])->id;
        } elseif ($scenario === 'deleted task') {
            $task->delete();
        } elseif ($scenario === 'missing child') {
            $childId = PHP_INT_MAX;
        } elseif ($scenario === 'deleted child') {
            $child = $this->child($task);
            $child->delete();
            $childId = $child->id;
        } else {
            $parent = $this->task($scenario === 'foreign child' ? User::factory()->create() : $user);
            $childId = $this->child($parent, ['title' => 'private title'])->id;
        }
        try {
            $this->select($user, $taskId, $childId);
            $this->fail('An invalid target must fail.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame([], $exception->getIds());
            $this->assertStringNotContainsString('private title', $exception->getMessage());
        }
    }

    public static function invalidTargets(): array
    {
        return array_map(fn ($case): array => [$case], ['missing task', 'foreign task', 'deleted task', 'missing child', 'foreign child', 'mismatched child', 'deleted child']);
    }

    #[DataProvider('malformedIds')]
    public function test_identifiers_are_genuine_positive_integers(mixed $taskId, mixed $childId): void
    {
        $user = User::factory()->create();
        $this->expectException(InvalidArgumentException::class);
        $this->select($user, $taskId, $childId);
    }

    public static function malformedIds(): array
    {
        $cases = [[null, 1]];
        foreach (['1', 1.0, true, false, 0, -1, [], (object) ['id' => 1]] as $bad) {
            $cases[] = [$bad, null];
            $cases[] = [1, $bad];
        }

        return $cases;
    }

    public function test_passed_trusted_user_overrides_authentication_and_cached_foreign_relations(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $task = $this->task($user);
        $this->task($foreign, ['priority' => TaskPriority::High, 'title' => 'private title']);
        $this->studySession($task, StudySessionStatus::Completed, ['user_id' => $foreign->id]);
        $this->actingAs($foreign);
        $user->setRelation('tasks', $foreign->tasks()->get());
        $user->setRelation('studySessions', $foreign->studySessions()->get());
        $this->assertSame([$this->item($task, null, null, [3600, 0, 3600, 0, 3600, 0])], $this->select($user)['work_items']);
        $this->assertStringNotContainsString('private title', json_encode($this->select($user), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('corruptReferences')]
    public function test_relevant_corrupt_sources_fail_closed_without_foreign_details(string $scenario): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $child = $this->child($task);
        $foreign = $this->task(User::factory()->create(), ['title' => 'private title']);
        $values = ['user_id' => $user->id];
        if ($scenario === 'foreign task owned child') {
            $values['task_id'] = $foreign->id;
            $values['subtask_id'] = $child->id;
        } elseif ($scenario === 'foreign child') {
            $values['subtask_id'] = $this->child($foreign)->id;
        } else {
            $other = $this->task($user);
            $values['subtask_id'] = $this->child($other)->id;
        }
        $this->studySession($task, StudySessionStatus::Completed, $values);
        try {
            $this->select($user, $task->id);
            $this->fail('Corrupt source references must fail.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('Invalid planning source relationships.', $exception->getMessage());
        }
    }

    public static function corruptReferences(): array
    {
        return [['foreign task owned child'], ['foreign child'], ['different owned parent']];
    }

    public function test_corruption_on_a_deleted_child_is_not_mistaken_for_valid_historical_deletion(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $child = $this->child($task);
        $child->delete();
        $foreign = $this->task(User::factory()->create());
        $this->studySession($foreign, StudySessionStatus::Completed, ['user_id' => $user->id, 'subtask_id' => $child->id]);
        $this->expectException(UnexpectedValueException::class);
        $this->select($user, $task->id);
    }

    public function test_explicit_selection_does_not_load_unrelated_source_history_or_deleted_tasks(): void
    {
        $user = User::factory()->create();
        $selected = $this->task($user);
        $other = $this->task($user);
        $deleted = $this->task($user);
        $deleted->delete();
        $this->studySession($other, StudySessionStatus::Completed, ['subtask_id' => $this->child($this->task(User::factory()->create()))->id]);
        $this->studySession($deleted, StudySessionStatus::Completed);
        $this->assertSame([$this->item($selected, null, null, [3600, 0, 3600, 0, 3600, 0])], $this->select($user, $selected->id)['work_items']);
    }

    #[DataProvider('badTimestampSources')]
    public function test_malformed_stored_timestamps_fail_with_controlled_errors(string $kind): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $child = $this->child($task);
        $session = $this->studySession($task, StudySessionStatus::Completed, ['subtask_id' => $child->id]);
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        try {
            DB::statement('SET SESSION sql_mode = ?', [implode(',', array_diff(explode(',', $mode), ['NO_ZERO_IN_DATE', 'NO_ZERO_DATE']))]);
            $table = match ($kind) {
                'task' => 'tasks', 'child' => 'subtasks', default => 'study_sessions'
            };
            $id = match ($kind) {
                'task' => $task->id, 'child' => $child->id, default => $session->id
            };
            $field = in_array($kind, ['task', 'child'], true) ? 'deadline_at' : ($kind === 'end' ? 'ends_at' : 'starts_at');
            $bad = $kind === 'end' ? '2027-01-00 12:00:00' : '2026-01-00 10:00:00';
            DB::table($table)->where('id', $id)->update([$field => $bad]);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->select($user);
    }

    public static function badTimestampSources(): array
    {
        return [['task'], ['child'], ['start'], ['end']];
    }

    public function test_utc_accounting_is_independent_of_server_and_user_timezone_and_dst(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $task = $this->task($user, ['deadline_at' => '2026-10-20 00:00:00']);
        $this->studySession($task, StudySessionStatus::Completed, ['starts_at' => '2026-03-29 00:30:00', 'ends_at' => '2026-03-29 01:30:00']);
        $this->studySession($task, StudySessionStatus::Completed, ['starts_at' => '2026-10-25 00:30:00', 'ends_at' => '2026-10-25 01:30:00']);
        $expected = $this->select($user);
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'Europe/Kyiv', 'America/Los_Angeles', 'Pacific/Apia'] as $zone) {
                date_default_timezone_set($zone);
                $user->timezone = $zone;
                $this->assertSame($expected, $this->select($user));
            }
        } finally {
            date_default_timezone_set($original);
        }
        $this->assertSame(7200, $expected['excluded_items'][0]['effort']['completed_credit_seconds']);
    }

    public function test_effort_calculation_is_delegated_with_exact_attributable_inputs(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $this->studySession($task, StudySessionStatus::Completed, ['actual_minutes' => 15]);
        $this->studySession($task, StudySessionStatus::Planned, ['ends_at' => '2026-10-12 11:15:01']);
        $this->mock(RemainingEffortService::class, function ($mock): void {
            $source = [['scheduled_seconds' => 3600, 'actual_minutes' => 15]];
            $mock->shouldReceive('calculate')->once()->with(null, $source, [])->andReturn($this->metrics([null, 900, null, 0, null, null]));
            $mock->shouldReceive('calculate')->once()->with(60, $source, [['scheduled_seconds' => 901]])->andReturn($this->metrics([3600, 900, 2700, 901, 1799, 0]));
        });
        $this->assertSame($this->metrics([3600, 900, 2700, 901, 1799, 0]), $this->select($user)['work_items'][0]['effort']);
    }

    public function test_clock_is_captured_once_even_if_it_changes_during_database_reads(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['estimated_minutes' => 120]);
        $this->studySession($task, StudySessionStatus::Planned);
        $changed = false;
        DB::listen(function () use (&$changed): void {
            if (! $changed) {
                $changed = true;
                $this->travelTo(CarbonImmutable::parse('2026-10-13T10:20:30Z'));
            }
        });
        $result = $this->select($user);
        $this->assertSame('2026-10-12T10:20:30.500000Z', $result['as_of_utc']);
        $this->assertSame($this->metrics([7200, 0, 7200, 3600, 3600, 0]), $result['work_items'][0]['effort']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_no_implicit_history_or_future_lookahead_cutoff_is_applied(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user);
        $this->studySession($task, StudySessionStatus::Completed, ['actual_minutes' => 15,
            'starts_at' => '1980-01-01 10:00:00', 'ends_at' => '1980-01-01 11:00:00']);
        $this->studySession($task, StudySessionStatus::Planned, [
            'starts_at' => '2040-01-01 10:00:00', 'ends_at' => '2040-01-01 10:45:00']);
        $result = $this->select($user);
        $this->assertSame([], $result['work_items']);
        $this->assertSame(['fully_reserved'], $result['excluded_items'][0]['reason_codes']);
        $this->assertSame($this->metrics([3600, 900, 2700, 2700, 0, 0]), $result['excluded_items'][0]['effort']);
    }

    public function test_repeated_selection_executes_only_reads_and_preserves_every_database_table(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['estimated_minutes' => 120]);
        $child = $this->child($task, ['estimated_minutes' => 120]);
        $this->studySession($task, StudySessionStatus::Completed, ['subtask_id' => $child->id, 'actual_minutes' => 15]);
        $this->studySession($task, StudySessionStatus::Planned, ['subtask_id' => $child->id]);
        Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 15]);
        $before = $this->snapshot();
        $level = DB::transactionLevel();
        $first = $this->select($user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame($first, $this->select($user));
            }
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_query_count_does_not_grow_with_work_or_session_count_and_reads_are_scoped(): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['estimated_minutes' => 120]);
        $child = $this->child($task, ['estimated_minutes' => 120]);
        $this->studySession($task, StudySessionStatus::Completed, ['subtask_id' => $child->id, 'actual_minutes' => 15]);
        $small = $this->queries($user);
        for ($i = 0; $i < 30; $i++) {
            $parent = $this->task($user, ['estimated_minutes' => 120]);
            $subtask = $this->child($parent, ['estimated_minutes' => 120]);
            $this->studySession($parent, StudySessionStatus::Completed, ['subtask_id' => $subtask->id, 'actual_minutes' => 15]);
            $this->studySession($parent, StudySessionStatus::Planned, ['subtask_id' => $subtask->id]);
        }
        $large = $this->queries($user);
        $this->assertCount(count($small), $large);
        $this->assertCount(4, $large);
        foreach ($large as $query) {
            $this->assertStringNotContainsString('select *', $query['query']);
            $this->assertStringNotContainsString('title', $query['query']);
            if (str_contains($query['query'], 'from `tasks`') || str_contains($query['query'], 'from `study_sessions`')) {
                $this->assertStringContainsString('`user_id` = ?', $query['query']);
                $this->assertSame($user->id, $query['bindings'][0]);
            }
        }
        $source = collect($large)->first(fn ($query): bool => str_contains($query['query'], 'from `study_sessions`'));
        $this->assertStringContainsString('`task_id` in', $source['query']);
        $this->assertStringContainsString('or `subtask_id` in', $source['query']);
        $this->assertStringNotContainsString('`starts_at` >=', $source['query']);
        $this->assertSame([$user->id, 'completed', 'planned'], array_slice($source['bindings'], 0, 3));
    }

    public function test_c10_remains_scheduled_workload_while_stage_a_uses_actual_effort(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:00:00Z'));
        $user = User::factory()->create(['timezone' => 'UTC']);
        $task = $this->task($user);
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => 1, 'starts_at' => '00:00:00', 'ends_at' => '24:00:00']);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 90]);
        $this->studySession($task, StudySessionStatus::Completed, ['starts_at' => '2026-10-12 08:00:00', 'ends_at' => '2026-10-12 09:00:00', 'actual_minutes' => 1]);
        $this->studySession($task, StudySessionStatus::Planned, ['starts_at' => '2026-10-12 09:00:00', 'ends_at' => '2026-10-12 09:30:00']);
        $before = $this->snapshot();
        $result = $this->select($user);
        $this->assertSame($this->metrics([3600, 60, 3540, 0, 3540, 0]), $result['work_items'][0]['effort']);
        $candidate = ['start' => $this->seconds('2026-10-12 10:00:00'), 'end' => $this->seconds('2026-10-12 11:00:00')];
        $this->assertSame(['daily_study_limit_exceeded'], app(PlanningCandidateEvaluationService::class)->evaluate($user, $candidate, $task->id));
        $this->assertSame($before, $this->snapshot());
    }

    private function select(User $user, mixed $taskId = null, mixed $childId = null): array
    {
        return app(PlanningWorkSelectionService::class)->select($user, $taskId, $childId);
    }

    private function task(User $user, array $values = []): Task
    {
        return Task::factory()->for($user)->create(['estimated_minutes' => 60,
            'completed_at' => ($values['status'] ?? null) === TaskStatus::Completed ? '2026-10-11 10:00:00' : null, ...$values]);
    }

    private function child(Task $task, array $values = []): Subtask
    {
        return Subtask::factory()->for($task)->create(['estimated_minutes' => 60,
            'completed_at' => ($values['status'] ?? null) === TaskStatus::Completed ? '2026-10-11 10:00:00' : null, ...$values]);
    }

    private function studySession(Task $task, StudySessionStatus $status, array $values = []): StudySession
    {
        return StudySession::factory()->for($task)->create(['user_id' => $task->user_id, 'status' => $status, 'starts_at' => '2026-10-12 11:00:00', 'ends_at' => '2026-10-12 12:00:00',
            'completed_at' => $status === StudySessionStatus::Completed ? '2026-10-12 12:00:00' : null, ...$values]);
    }

    private function metrics(array $values): array
    {
        return array_combine(['estimated_seconds', 'completed_credit_seconds', 'remaining_work_seconds', 'future_reserved_seconds', 'unallocated_work_seconds', 'over_reserved_seconds'], $values);
    }

    private function item(Task $task, ?Subtask $child, ?int $deadline, ?array $metrics): array
    {
        return ['task_id' => $task->id, 'subtask_id' => $child?->id, 'work_type' => $child === null ? 'task' : 'subtask',
            'effective_deadline_utc' => $deadline, 'priority' => $task->priority->value, 'position' => $child?->position,
            'effort' => $metrics === null ? null : $this->metrics($metrics)];
    }

    private function warning(string $code, int $taskId, ?int $childId, ?int $sessionId, int $seconds): array
    {
        return ['code' => $code, 'task_id' => $taskId, 'subtask_id' => $childId, 'study_session_id' => $sessionId, 'seconds' => $seconds];
    }

    private function seconds(string $utc): int
    {
        return (new DateTimeImmutable($utc.'Z'))->getTimestamp();
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $rows = DB::table($table)->get()->map(fn ($row): array => (array) $row)->all();
            usort($rows, fn ($a, $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $result[$table] = $rows;
        }
        ksort($result);

        return $result;
    }

    private function queries(User $user): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->select($user);

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
    }
}
