<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    private const EDITABLE_FIELDS = ['title', 'description', 'subject_id', 'priority', 'estimated_minutes', 'deadline_at'];

    public function create(User $user, array $values): Task
    {
        return DB::transaction(function () use ($user, $values): Task {
            $this->lockOwner($user);
            $candidate = ['description' => null, 'subject_id' => null, 'priority' => TaskPriority::Normal,
                'estimated_minutes' => null, 'deadline_at' => null, ...$this->editableValues($values)];
            $this->validateCandidate($user, $candidate);

            return $user->tasks()->create([
                ...$candidate, 'status' => TaskStatus::Pending, 'completed_at' => null,
            ])->refresh();
        });
    }

    public function update(User $user, Task $task, array $values): Task
    {
        return DB::transaction(function () use ($user, $task, $values): Task {
            $this->lockOwner($user);
            $task = $user->tasks()->lockForUpdate()->findOrFail($task->getKey());
            $candidate = $task->only(self::EDITABLE_FIELDS);
            $deadline = $task->getRawOriginal('deadline_at');
            $candidate['deadline_at'] = $deadline === null ? null
                : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $deadline, 'UTC');
            $candidate = array_replace($candidate, $this->editableValues($values));
            $this->validateCandidate($user, $candidate, $task);
            $task->update($candidate);

            return $task->refresh();
        });
    }

    public function complete(User $user, Task $task): Task
    {
        return DB::transaction(function () use ($user, $task): Task {
            $this->lockOwner($user);
            $task = $user->tasks()->lockForUpdate()->findOrFail($task->getKey());
            if ($task->status === TaskStatus::Pending) {
                $task->update([
                    'status' => TaskStatus::Completed,
                    'completed_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                ]);
            }

            return $task->refresh();
        });
    }

    public function reopen(User $user, Task $task): Task
    {
        return DB::transaction(function () use ($user, $task): Task {
            $this->lockOwner($user);
            $task = $user->tasks()->lockForUpdate()->findOrFail($task->getKey());
            if ($task->status === TaskStatus::Completed) {
                $task->update(['status' => TaskStatus::Pending, 'completed_at' => null]);
            }

            return $task->refresh();
        });
    }

    private function lockOwner(User $user): void
    {
        User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function editableValues(array $values): array
    {
        $values = Arr::only($values, self::EDITABLE_FIELDS);
        if (isset($values['deadline_at'])) {
            $values['deadline_at'] = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $values['deadline_at'], 'UTC');
        }

        return $values;
    }

    private function validateCandidate(User $user, array $candidate, ?Task $task = null): void
    {
        if ($candidate['subject_id'] !== null
            && $user->subjects()->whereKey($candidate['subject_id'])->lockForUpdate()->first() === null) {
            throw ValidationException::withMessages(['subject_id' => ['The selected subject is invalid.']]);
        }

        if ($task !== null && $candidate['deadline_at'] !== null
            && $task->subtasks()->whereNotNull('deadline_at')->where('deadline_at', '>', $candidate['deadline_at'])
                ->lockForUpdate()->first() !== null) {
            throw ValidationException::withMessages(['deadline_at' => ['The task deadline must not be earlier than an existing subtask deadline.']]);
        }
    }
}
