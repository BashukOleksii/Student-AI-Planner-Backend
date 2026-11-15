<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningWorkSelectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('integration')]
#[Group('mysql')]
class CompactPlanningWorkSelectionServiceTest extends TestCase
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
        $this->assertSame(['as_of_utc' => '2026-10-12T10:20:30.500000Z', 'work_items' => [$this->item($task, null, null, [3600, 0, 3600, 0, 3600, 0])], 'excluded_items' => [], 'warnings' => []], $this->select($user));
    }

    #[DataProvider('completionStates')]
    public function test_parent_and_child_statuses_are_independent_and_excluded_without_mutation(bool $parentComplete, bool $childComplete): void
    {
        $user = User::factory()->create();
        $task = $this->task($user, ['status' => $parentComplete ? TaskStatus::Completed : TaskStatus::Pending]);
        $child = $this->child($task, ['estimated_minutes' => 30, 'status' => $childComplete ? TaskStatus::Completed : TaskStatus::Pending]);
        $before = [$task->refresh()->getAttributes(), $child->refresh()->getAttributes()];
        $parent = $this->item($task, null, null, null);
        $parent['reason_codes'] = ['subtasks_authoritative', ...$parentComplete ? ['completed_task'] : []];
        $unit = $this->item($task, $child, null, [1800, 0, 1800, 0, 1800, 0]);
        $result = $this->select($user);
        if (! $parentComplete && ! $childComplete) {
            $this->assertSame([$unit], $result['work_items']);
            $this->assertSame([$parent], $result['excluded_items']);
        } else {
            $unit['reason_codes'] = [...$parentComplete ? ['parent_task_completed'] : [], ...$childComplete ? ['completed_subtask'] : []];
            $this->assertSame([], $result['work_items']);
            $this->assertSame([$parent, $unit], $result['excluded_items']);
        }
        $this->assertSame($before, [$task->refresh()->getAttributes(), $child->refresh()->getAttributes()]);
    }

    public static function completionStates(): array
    {
        return [0 => [false, false]];
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
        $this->assertSame([$this->item($task, $first, null, [1800, 600, 1200, 0, 1200, 0]), $this->item($task, $second, null, [2400, 0, 2400, 900, 1500, 0])], $result['work_items']);
        $this->assertSame([$this->warning('ambiguous_parent_session_attribution', $task->id, null, $parentCredit->id, 7200), $this->warning('ambiguous_parent_session_attribution', $task->id, null, $parentReservation->id, 3600), $this->warning('session_targets_deleted_subtask', $task->id, $deleted->id, $deletedCredit->id, 7200), $this->warning('session_targets_deleted_subtask', $task->id, $deleted->id, $deletedReservation->id, 3600)], $result['warnings']);
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
        $this->assertSame($status === StudySessionStatus::Planned && ! $future ? [$this->warning('stale_planned_session', $task->id, null, $source->id, 3600)] : [], $result['warnings']);
    }

    public static function sessionStates(): array
    {
        return ['planned historical' => [StudySessionStatus::Planned, false]];
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
        return [0 => [null, '2000-01-01 10:15:01', 901, [], []]];
    }

    private function select(User $user, mixed $taskId = null, mixed $childId = null): array
    {
        return app(PlanningWorkSelectionService::class)->select($user, $taskId, $childId);
    }

    private function task(User $user, array $values = []): Task
    {
        return Task::factory()->for($user)->create(['estimated_minutes' => 60, 'completed_at' => ($values['status'] ?? null) === TaskStatus::Completed ? '2026-10-11 10:00:00' : null, ...$values]);
    }

    private function child(Task $task, array $values = []): Subtask
    {
        return Subtask::factory()->for($task)->create(['estimated_minutes' => 60, 'completed_at' => ($values['status'] ?? null) === TaskStatus::Completed ? '2026-10-11 10:00:00' : null, ...$values]);
    }

    private function studySession(Task $task, StudySessionStatus $status, array $values = []): StudySession
    {
        return StudySession::factory()->for($task)->create(['user_id' => $task->user_id, 'status' => $status, 'starts_at' => '2026-10-12 11:00:00', 'ends_at' => '2026-10-12 12:00:00', 'completed_at' => $status === StudySessionStatus::Completed ? '2026-10-12 12:00:00' : null, ...$values]);
    }

    private function metrics(array $values): array
    {
        return array_combine(['estimated_seconds', 'completed_credit_seconds', 'remaining_work_seconds', 'future_reserved_seconds', 'unallocated_work_seconds', 'over_reserved_seconds'], $values);
    }

    private function item(Task $task, ?Subtask $child, ?int $deadline, ?array $metrics): array
    {
        return ['task_id' => $task->id, 'subtask_id' => $child?->id, 'work_type' => $child === null ? 'task' : 'subtask', 'effective_deadline_utc' => $deadline, 'priority' => $task->priority->value, 'position' => $child?->position, 'effort' => $metrics === null ? null : $this->metrics($metrics)];
    }

    private function warning(string $code, int $taskId, ?int $childId, ?int $sessionId, int $seconds): array
    {
        return ['code' => $code, 'task_id' => $taskId, 'subtask_id' => $childId, 'study_session_id' => $sessionId, 'seconds' => $seconds];
    }
}
