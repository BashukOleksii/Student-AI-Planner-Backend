<?php

namespace App\Services\Planning;

use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use UnexpectedValueException;

/** Read-only owned work selection; eligibility does not prove calendar feasibility. */
class PlanningWorkSelectionService
{
    public function __construct(private RemainingEffortService $effort, private TimeIntervalService $intervals) {}

    /**
     * @return array{as_of_utc: string, work_items: list<array>, excluded_items: list<array>, warnings: list<array>}
     *
     * @throws InvalidArgumentException For malformed arguments, stored timestamps or effort values.
     * @throws ModelNotFoundException For missing, foreign, deleted or incorrectly nested explicit targets.
     * @throws UnexpectedValueException For incompatible selected session references, without private details.
     */
    public function select(User $user, mixed $taskId = null, mixed $subtaskId = null): array
    {
        foreach ([$taskId, $subtaskId] as $id) {
            if ($id !== null && (! is_int($id) || $id < 1)) {
                throw new InvalidArgumentException('Work identifiers must be null or positive integers.');
            }
        }
        if ($subtaskId !== null && $taskId === null) {
            throw new InvalidArgumentException('A subtask requires its parent task identifier.');
        }
        $now = CarbonImmutable::now('UTC');
        $user = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id']);
        $tasks = $user->tasks()->when($taskId !== null, fn ($query) => $query->whereKey($taskId))
            ->with(['subtasks' => fn ($query) => $query->withTrashed()->select([
                'id', 'task_id', 'status', 'position', 'estimated_minutes', 'deadline_at', 'deleted_at',
            ])])->get(['id', 'user_id', 'status', 'priority', 'estimated_minutes', 'deadline_at'])->keyBy('id');
        if ($taskId !== null && ! $tasks->has($taskId)) {
            throw (new ModelNotFoundException)->setModel(Task::class);
        }
        if ($subtaskId !== null && ! $tasks[$taskId]->subtasks->contains(fn ($child): bool => $child->id === $subtaskId && ! $child->trashed())) {
            throw (new ModelNotFoundException)->setModel(Subtask::class);
        }
        $result = ['as_of_utc' => $now->format('Y-m-d\TH:i:s.u\Z'), 'work_items' => [], 'excluded_items' => [], 'warnings' => []];
        if ($tasks->isEmpty()) {
            return $result;
        }
        $children = $tasks->flatMap(fn ($task) => $task->subtasks)->keyBy('id');
        // Child matching also discovers corrupted rows whose task_id points outside this owned scope.
        $sessions = $user->studySessions()->whereIn('status', [StudySessionStatus::Completed, StudySessionStatus::Planned])
            ->where(fn ($query) => $query->whereIn('task_id', $tasks->modelKeys())->orWhereIn('subtask_id', $children->keys()->all()))
            ->get(['id', 'user_id', 'task_id', 'subtask_id', 'status', 'starts_at', 'ends_at', 'actual_minutes']);
        $this->validateReferences($user, $sessions, $tasks, $children);

        $units = $completed = $reserved = [];
        foreach ($tasks as $task) {
            $parentDeadline = $this->deadline($task);
            $active = $task->subtasks->reject(fn ($child): bool => $child->trashed());
            if ($active->isEmpty()) {
                $units[$this->unitKey($task->id, null)] = [$task, null, $parentDeadline];
            } else {
                if ($subtaskId === null) {
                    $parent = $this->item($task, null, $parentDeadline, null);
                    $parent['reason_codes'] = ['subtasks_authoritative'];
                    if ($task->status === TaskStatus::Completed) {
                        $parent['reason_codes'][] = 'completed_task';
                    }
                    $result['excluded_items'][] = $parent;
                }
                foreach ($active as $child) {
                    if ($subtaskId === null || $child->id === $subtaskId) {
                        $deadline = $this->effectiveDeadline($task, $child);
                        $units[$this->unitKey($task->id, $child->id)] = [$task, $child, $deadline];
                    }
                }
            }
        }

        foreach ($sessions as $session) {
            $start = $this->utcDate($session->getRawOriginal('starts_at'));
            $end = $this->utcDate($session->getRawOriginal('ends_at'));
            $duration = $this->intervals->durationSeconds(['start' => $start->getTimestamp(), 'end' => $end->getTimestamp()]);
            $source = ['scheduled_seconds' => $duration, 'actual_minutes' => $session->actual_minutes];
            $isCompleted = $session->status === StudySessionStatus::Completed;
            $credit = $isCompleted ? $this->effort->calculate(null, [$source], [])['completed_credit_seconds'] : $duration;
            $key = $this->unitKey($session->task_id, $session->subtask_id);
            // Preserve source validation for siblings, but keep explicitly targeted diagnostics focused.
            if ($subtaskId !== null && $session->subtask_id !== null && $session->subtask_id !== $subtaskId) {
                continue;
            }
            $task = $tasks[$session->task_id];
            $child = $session->subtask_id === null ? null : $children[$session->subtask_id];
            $deadline = $this->effectiveDeadline($task, $child);
            $invalid = false;
            if (! $isCompleted) {
                if ($start < $now) {
                    $result['warnings'][] = $this->warning('stale_planned_session', $task->id, $child?->id, $session->id, $duration);
                    $invalid = true;
                }
                if ($deadline !== null && $end->getTimestamp() > $deadline) {
                    $result['warnings'][] = $this->warning('reservation_deadline_exceeded', $task->id, $child?->id, $session->id, $duration);
                    $invalid = true;
                }
                if ($task->status === TaskStatus::Completed || $child?->status === TaskStatus::Completed) {
                    $result['warnings'][] = $this->warning('reservation_targets_completed_work', $task->id, $child?->id, $session->id, $duration);
                    $invalid = true;
                }
            }
            if ($session->subtask_id !== null && $children[$session->subtask_id]->trashed()) {
                if ($subtaskId === null) {
                    $result['warnings'][] = $this->warning('session_targets_deleted_subtask', $session->task_id, $session->subtask_id, $session->id, $credit);
                }

                continue;
            }
            if ($session->subtask_id === null && ! isset($units[$key])) {
                $result['warnings'][] = $this->warning('ambiguous_parent_session_attribution', $session->task_id, null, $session->id, $credit);

                continue;
            }
            if (! isset($units[$key])) {
                continue;
            }
            if ($isCompleted) {
                $completed[$key][] = $source;

                continue;
            }
            if (! $invalid) {
                $reserved[$key][] = ['scheduled_seconds' => $duration];
            }
        }

        foreach ($units as $key => [$task, $child, $deadline]) {
            $metrics = $this->effort->calculate(($child ?? $task)->estimated_minutes, $completed[$key] ?? [], $reserved[$key] ?? []);
            $item = $this->item($task, $child, $deadline, $metrics);
            $reasons = [];
            if ($task->status === TaskStatus::Completed) {
                $reasons[] = $child === null ? 'completed_task' : 'parent_task_completed';
            }
            if ($child?->status === TaskStatus::Completed) {
                $reasons[] = 'completed_subtask';
            }
            if ($metrics['estimated_seconds'] === null) {
                $reasons[] = 'missing_estimate';
            }
            if ($deadline !== null && (new DateTimeImmutable('@'.$deadline)) <= $now) {
                $reasons[] = 'deadline_expired';
            }
            if ($metrics['remaining_work_seconds'] === 0) {
                $reasons[] = 'completed_effort_satisfies_estimate';
            } elseif ($metrics['unallocated_work_seconds'] === 0) {
                $reasons[] = 'fully_reserved';
            }
            if ($metrics['estimated_seconds'] !== null && $metrics['completed_credit_seconds'] > $metrics['estimated_seconds']) {
                $result['warnings'][] = $this->warning('completed_effort_exceeds_estimate', $task->id, $child?->id, null,
                    $metrics['completed_credit_seconds'] - $metrics['estimated_seconds']);
            }
            if ($metrics['over_reserved_seconds'] > 0) {
                $result['warnings'][] = $this->warning('future_reservation_exceeds_remaining_work', $task->id, $child?->id, null, $metrics['over_reserved_seconds']);
            }
            if ($reasons === []) {
                $result['work_items'][] = $item;
            } else {
                $item['reason_codes'] = $reasons;
                $result['excluded_items'][] = $item;
            }
        }
        usort($result['work_items'], $this->compareItems(...));
        usort($result['excluded_items'], $this->compareItems(...));
        usort($result['warnings'], fn ($a, $b): int => [$a['task_id'], $a['subtask_id'] ?? 0, $a['study_session_id'] ?? 0, $a['code']]
            <=> [$b['task_id'], $b['subtask_id'] ?? 0, $b['study_session_id'] ?? 0, $b['code']]);

        return $result;
    }

    private function validateReferences(User $user, Collection $sessions, Collection $tasks, Collection $children): void
    {
        foreach ($sessions as $session) {
            if ($session->user_id !== $user->id || ! $tasks->has($session->task_id)
                || ($session->subtask_id !== null && (! $children->has($session->subtask_id)
                    || $children[$session->subtask_id]->task_id !== $session->task_id))) {
                throw new UnexpectedValueException('Invalid planning source relationships.');
            }
        }
    }

    private function item(Task $task, ?Subtask $child, ?int $deadline, ?array $metrics): array
    {
        return ['task_id' => $task->id, 'subtask_id' => $child?->id, 'work_type' => $child === null ? 'task' : 'subtask',
            'effective_deadline_utc' => $deadline, 'priority' => $task->priority->value, 'position' => $child?->position, 'effort' => $metrics];
    }

    private function warning(string $code, int $taskId, ?int $subtaskId, ?int $sessionId, int $seconds): array
    {
        return ['code' => $code, 'task_id' => $taskId, 'subtask_id' => $subtaskId, 'study_session_id' => $sessionId, 'seconds' => $seconds];
    }

    private function compareItems(array $a, array $b): int
    {
        return (($a['effective_deadline_utc'] === null) <=> ($b['effective_deadline_utc'] === null))
            ?: ($a['effective_deadline_utc'] <=> $b['effective_deadline_utc'])
            ?: ($b['priority'] <=> $a['priority']) ?: ($a['task_id'] <=> $b['task_id'])
            ?: (($a['position'] ?? 0) <=> ($b['position'] ?? 0)) ?: (($a['subtask_id'] ?? 0) <=> ($b['subtask_id'] ?? 0));
    }

    private function unitKey(int $taskId, ?int $subtaskId): string
    {
        return $taskId.':'.($subtaskId ?? 'task');
    }

    private function deadline(Task|Subtask $work): ?int
    {
        $value = $work->getRawOriginal('deadline_at');

        return $value === null ? null : $this->utcDate($value)->getTimestamp();
    }

    private function effectiveDeadline(Task $task, ?Subtask $child): ?int
    {
        $parent = $this->deadline($task);
        $deadline = $child === null ? null : $this->deadline($child);

        return $parent === null ? $deadline : ($deadline === null ? $parent : min($parent, $deadline));
    }

    private function utcDate(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value)
            || $value < '1000-01-01 00:00:00' || $value > '9999-12-31 23:59:59') {
            throw new InvalidArgumentException('Expected a valid whole-second UTC database timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Expected a valid whole-second UTC database timestamp.');
        }

        return $date;
    }
}
