<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubtaskService
{
    private const EDITABLE_FIELDS = ['title', 'description', 'estimated_minutes', 'deadline_at'];

    /** @return Collection<int, Subtask> */
    public function all(User $user, Task $task): Collection
    {
        $task = $user->tasks()->findOrFail($task->getKey());

        return $task->subtasks()->orderBy('position')->orderBy('id')->get();
    }

    public function find(User $user, Task $task, Subtask $subtask): Subtask
    {
        $task = $user->tasks()->findOrFail($task->getKey());

        return $task->subtasks()->findOrFail($subtask->getKey());
    }

    public function create(User $user, Task $task, array $values): Subtask
    {
        return DB::transaction(function () use ($user, $task, $values): Subtask {
            $task = $this->lockParent($user, $task);
            $candidate = ['description' => null, 'estimated_minutes' => null, 'deadline_at' => null,
                ...$this->editableValues($values)];
            $this->validateDeadline($task, $candidate);
            $last = $task->subtasks()->orderByDesc('position')->lockForUpdate()->first(['id', 'position']);
            $position = ($last?->position ?? 0) + 1;
            if ($position > 65535) {
                throw ValidationException::withMessages(['position' => ['No append position remains for this task.']]);
            }

            return $task->subtasks()->create([
                ...$candidate, 'position' => $position, 'status' => TaskStatus::Pending, 'completed_at' => null,
            ])->refresh();
        });
    }

    public function update(User $user, Task $task, Subtask $subtask, array $values): Subtask
    {
        return DB::transaction(function () use ($user, $task, $subtask, $values): Subtask {
            $task = $this->lockParent($user, $task);
            $subtask = $task->subtasks()->lockForUpdate()->findOrFail($subtask->getKey());
            $candidate = $subtask->only(self::EDITABLE_FIELDS);
            $deadline = $subtask->getRawOriginal('deadline_at');
            $candidate['deadline_at'] = $deadline === null ? null
                : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $deadline, 'UTC');
            $candidate = array_replace($candidate, $this->editableValues($values));
            $this->validateDeadline($task, $candidate);
            $subtask->update($candidate);

            return $subtask->refresh();
        });
    }

    public function complete(User $user, Task $task, Subtask $subtask): Subtask
    {
        return DB::transaction(function () use ($user, $task, $subtask): Subtask {
            $task = $this->lockParent($user, $task);
            $subtask = $task->subtasks()->lockForUpdate()->findOrFail($subtask->getKey());
            if ($subtask->status === TaskStatus::Pending) {
                $subtask->update([
                    'status' => TaskStatus::Completed,
                    'completed_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                ]);
            }

            return $subtask->refresh();
        });
    }

    public function reopen(User $user, Task $task, Subtask $subtask): Subtask
    {
        return DB::transaction(function () use ($user, $task, $subtask): Subtask {
            $task = $this->lockParent($user, $task);
            $subtask = $task->subtasks()->lockForUpdate()->findOrFail($subtask->getKey());
            if ($subtask->status === TaskStatus::Completed) {
                $subtask->update(['status' => TaskStatus::Pending, 'completed_at' => null]);
            }

            return $subtask->refresh();
        });
    }

    /** @return Collection<int, Subtask> */
    public function reorder(User $user, Task $task, array $subtaskIds): Collection
    {
        return DB::transaction(function () use ($user, $task, $subtaskIds): Collection {
            $task = $this->lockParent($user, $task);
            // Only identity/position is needed for validation; avoid hydrating full models here.
            $active = $task->subtasks()->orderBy('id')->lockForUpdate()->toBase()->get(['id', 'position'])->keyBy('id');
            if ($active->count() > 65535) {
                throw ValidationException::withMessages(['subtask_ids' => ['Too many active subtasks to assign positions.']]);
            }
            if (! array_is_list($subtaskIds) || count($subtaskIds) !== $active->count()) {
                $this->invalidPermutation();
            }
            $seen = [];
            foreach ($subtaskIds as $id) {
                if (! is_int($id) || $id < 1 || ! $active->has($id) || isset($seen[$id])) {
                    $this->invalidPermutation();
                }
                $seen[$id] = true;
            }

            foreach ($subtaskIds as $index => $id) {
                $position = $index + 1;
                if ((int) $active[$id]->position !== $position) {
                    $task->subtasks()->whereKey($id)->update(['position' => $position]);
                }
            }

            return $task->subtasks()->orderBy('position')->orderBy('id')->get();
        });
    }

    private function invalidPermutation(): never
    {
        throw ValidationException::withMessages(['subtask_ids' => ['The subtask ids must contain every active subtask of this task exactly once.']]);
    }

    private function lockParent(User $user, Task $task): Task
    {
        User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

        return $user->tasks()->lockForUpdate()->findOrFail($task->getKey());
    }

    private function editableValues(array $values): array
    {
        $values = Arr::only($values, self::EDITABLE_FIELDS);
        if (isset($values['deadline_at'])) {
            $values['deadline_at'] = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $values['deadline_at'], 'UTC');
        }

        return $values;
    }

    private function validateDeadline(Task $task, array $candidate): void
    {
        $parentDeadline = $task->getRawOriginal('deadline_at');
        if ($parentDeadline !== null && $candidate['deadline_at'] !== null
            && $candidate['deadline_at']->greaterThan(CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $parentDeadline, 'UTC'))) {
            throw ValidationException::withMessages(['deadline_at' => ['The subtask deadline must not be later than the task deadline.']]);
        }
    }
}
