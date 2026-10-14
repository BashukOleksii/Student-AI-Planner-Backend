<?php

namespace App\Services\Reminders;

use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** Shared persisted target ownership/attribution, eligibility and exact anchor sources. */
class ReminderTargetService
{
    public function __construct(private ReminderRules $rules, private ReminderDependencyService $dependencies) {}

    /** Shared scheduled Reminder structure; eligibility is a separate caller decision. */
    public function forReminder(User $owner, Reminder $reminder, bool $lock): array
    {
        $targets = $this->rules->targets($reminder->getAttributes());
        if ($reminder->user_id !== $owner->id) {
            throw new UnexpectedValueException('Invalid reminder relationships.');
        }
        if ($targets === []) {
            if ($reminder->anchor !== null || $reminder->offset_minutes !== null || ! $this->rules->meaningful($reminder->message)) {
                throw new UnexpectedValueException('Invalid reminder relationships.');
            }
        } elseif (! $this->dependencies->compatible($reminder, $owner)) {
            throw new UnexpectedValueException('Invalid reminder relationships.');
        }

        return $this->resolve($owner, $targets, $lock, false);
    }

    /** Structural checks for reads/history; callers opt into creation/offset-edit eligibility. */
    public function resolve(User $owner, array $data, bool $lock, bool $create): array
    {
        $targets = $this->rules->targets($data);
        if ($targets === []) {
            return ['task' => null, 'child' => null, 'session' => null];
        }
        $field = array_key_first($targets);
        $id = $targets[$field];
        $session = $field === 'study_session_id' ? $owner->studySessions()->whereKey($id)->first() : null;
        $childSource = $field === 'subtask_id' ? Subtask::withTrashed()->whereKey($id)->first() : null;
        $taskId = match ($field) {
            'task_id' => $id, 'subtask_id' => $childSource?->task_id, 'study_session_id' => $session?->task_id,
        };
        $query = $owner->tasks()->withTrashed()->whereKey($taskId);
        $task = ($lock ? $query->lockForUpdate() : $query)->first();
        $childId = $field === 'subtask_id' ? $id : $session?->subtask_id;
        $query = $task?->subtasks()->withTrashed()->whereKey($childId);
        $child = $query === null || $childId === null ? null : ($lock ? $query->lockForUpdate() : $query)->first();
        if ($session !== null && $lock) {
            $session = $owner->studySessions()->whereKey($id)->lockForUpdate()->first();
        }
        $valid = $task !== null && ($childId === null || $child !== null)
            && ($field !== 'subtask_id' || $child !== null)
            && ($field !== 'study_session_id' || ($session !== null && $this->attributed($owner, $task, $child, $session)));
        if ($create && $valid) {
            $valid = $this->eligible(compact('task', 'child', 'session'));
        }
        if (! $valid) {
            if ($create) {
                throw ValidationException::withMessages(['target' => 'The reminder target is unavailable.']);
            }
            throw new UnexpectedValueException('Invalid reminder relationships.');
        }

        return compact('task', 'child', 'session');
    }

    public function attributed(User $owner, Task $task, ?Subtask $child, StudySession $session): bool
    {
        return $this->workOwned($owner, $task, $child) && $session->user_id === $owner->id && $session->task_id === $task->id
            && $session->subtask_id === $child?->id;
    }

    public function workOwned(User $owner, Task $task, ?Subtask $child): bool
    {
        return $task->user_id === $owner->id && ($child === null || $child->task_id === $task->id);
    }

    public function eligible(array $context, bool $requireFutureSession = true): bool
    {
        ['task' => $task, 'child' => $child, 'session' => $session] = $context;

        return $task === null || (! $task->trashed() && $task->status === TaskStatus::Pending
            && ($child === null || (! $child->trashed() && $child->status === TaskStatus::Pending))
            && ($session === null || ($session->status === StudySessionStatus::Planned
                && (! $requireFutureSession || CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $session->getRawOriginal('starts_at'), 'UTC') > CarbonImmutable::now('UTC')))));
    }

    public function anchor(array $context): ?string
    {
        $raw = $context['session'] !== null ? $context['session']->getRawOriginal('starts_at')
            : ($context['child'] ?? $context['task'])?->getRawOriginal('deadline_at');

        return $raw === null ? null : $this->rules->wireTime($raw);
    }
}
