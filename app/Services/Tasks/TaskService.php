<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    private const EDITABLE_FIELDS = ['title', 'description', 'subject_id', 'priority', 'estimated_minutes', 'deadline_at'];

    /** @return LengthAwarePaginator<int, Task> */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        if (isset($filters['subject_id']) && ! $user->subjects()->whereKey($filters['subject_id'])->exists()) {
            throw ValidationException::withMessages(['subject_id' => ['The selected subject is invalid.']]);
        }

        $from = isset($filters['deadline_from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $filters['deadline_from'], 'UTC') : null;
        $to = isset($filters['deadline_to'])
            ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $filters['deadline_to'], 'UTC') : null;
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            throw ValidationException::withMessages(['deadline_to' => ['The upper deadline bound must not be earlier than the lower bound.']]);
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 15);
        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw ValidationException::withMessages(['page' => ['The selected page is too large.']]);
        }

        $query = $user->tasks()->with([
            'subject' => fn ($query) => $query->where('user_id', $user->getKey())->select(['id', 'user_id']),
        ]);
        foreach (['status', 'priority', 'subject_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->when($from !== null, fn ($query) => $query->where('deadline_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('deadline_at', '<=', $to))
            ->orderByRaw('deadline_at IS NULL')->orderBy('deadline_at')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends(Arr::only($filters, ['status', 'priority', 'subject_id', 'deadline_from', 'deadline_to', 'per_page']));
    }

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
