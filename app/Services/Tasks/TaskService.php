<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\ReminderIntegrityException;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderSynchronizationService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TaskService
{
    private const EDITABLE_FIELDS = ['title', 'description', 'subject_id', 'priority', 'estimated_minutes', 'deadline_at'];

    public function __construct(private TaskDependencyCleanupService $cleanup, private ReminderSynchronizationService $reminders,
        private TaskEstimateService $estimates, private WorkCompletionService $completion) {}

    public function delete(User $user, Task $task): void
    {
        DB::transaction(function () use ($user, $task): void {
            $this->lockOwner($user);
            $task = $user->tasks()->lockForUpdate()->findOrFail($task->getKey());
            $children = $task->subtasks()->withTrashed()->orderBy('id')->lockForUpdate()->get();
            $this->cleanup->cancel($user, $task, $children->modelKeys(), true);
            foreach ($children as $child) {
                if (! $child->trashed()) {
                    $child->delete();
                }
            }
            $task->delete();
        });
    }

    /** @return array{task_id: int, total_subtasks: int, completed_subtasks: int, progress_percent: ?int} */
    public function progress(User $user, Task $task): array
    {
        $task = $user->tasks()->findOrFail($task->getKey());
        $counts = $task->subtasks()->selectRaw(
            'COUNT(*) AS total_subtasks, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed_subtasks',
            [TaskStatus::Completed->value],
        )->toBase()->first();
        $total = (int) $counts->total_subtasks;
        $completed = (int) $counts->completed_subtasks;

        return [
            'task_id' => $task->getKey(),
            'total_subtasks' => $total,
            'completed_subtasks' => $completed,
            // Positive integer arithmetic implements nearest-integer, half-up rounding.
            'progress_percent' => $total === 0 ? null : intdiv($completed * 100 + intdiv($total, 2), $total),
        ];
    }

    /** @return LengthAwarePaginator<int, Task> */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        if (isset($filters['subject_id']) && ! $user->subjects()->whereKey($filters['subject_id'])->exists()) {
            throw ValidationException::withMessages(['subject_id' => ['Вибраний предмет недоступний.']]);
        }

        $from = isset($filters['deadline_from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $filters['deadline_from'], 'UTC') : null;
        $to = isset($filters['deadline_to'])
            ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $filters['deadline_to'], 'UTC') : null;
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            throw ValidationException::withMessages(['deadline_to' => ['Верхня межа дедлайну не може бути раніше нижньої.']]);
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 15);
        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw ValidationException::withMessages(['page' => ['Номер сторінки завеликий.']]);
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
            $candidate = $this->prepare($user, $values, null, true);

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
            $deadline = $task->getRawOriginal('deadline_at');
            $candidate = $this->prepare($user, $values, $task, true);
            if (! $task->update($candidate)) {
                throw new ReminderIntegrityException;
            }
            if ($deadline !== $task->getRawOriginal('deadline_at')) {
                $this->reminders->deadlineChanged($user, $task);
            }

            return $task->refresh();
        });
    }

    public function complete(User $user, Task $task, ?string $completionToken = null, ?CarbonImmutable &$operationNow = null): Task
    {
        return DB::transaction(function () use ($user, $task, $completionToken, &$operationNow): Task {
            $this->lockOwner($user);
            $task = $user->tasks()->lockForUpdate()->findOrFail($task->getKey());
            if ($task->status->isUnfinished()) {
                $now = CarbonImmutable::now('UTC');
                $this->completion->cancel($user, $task, null, $now, $completionToken);
                $operationNow = $now;
                if (! $task->update([
                    'status' => TaskStatus::Completed,
                    'completed_at' => $now->startOfSecond(),
                ])) {
                    throw new ReminderIntegrityException;
                }
                $this->reminders->completed($user, $task);
            }

            return $task->refresh();
        });
    }

    public function reopen(User $user, Task $task): Task
    {
        return DB::transaction(function () use ($user, $task): Task {
            $this->lockOwner($user);
            $task = $user->tasks()->lockForUpdate()->findOrFail($task->getKey());
            if ($task->status !== TaskStatus::Pending) {
                if (! $task->update(['status' => TaskStatus::Pending, 'completed_at' => null])) {
                    throw new ReminderIntegrityException;
                }
            }

            return $task->refresh();
        });
    }

    public function miss(User $user, Task $task): Task
    {
        return DB::transaction(function () use ($user, $task): Task {
            $this->lockOwner($user);
            $task = $user->tasks()->lockForUpdate()->findOrFail($task->getKey());
            if ($task->status === TaskStatus::Completed) {
                throw new ConflictHttpException('Спочатку поновіть виконане завдання, щоб позначити його незавершеним.');
            }
            if ($task->status !== TaskStatus::Missed) {
                if (! $task->update(['status' => TaskStatus::Missed, 'completed_at' => null])) {
                    throw new ReminderIntegrityException;
                }
            }

            return $task->refresh();
        });
    }

    /** Read-only complete-candidate validation, also used by the authoritative writers. */
    public function prepare(User $user, array $values, ?Task $task = null, bool $lock = false): array
    {
        $candidate = $task === null
            ? ['description' => null, 'subject_id' => null, 'priority' => TaskPriority::Normal,
                'estimated_minutes' => null, 'deadline_at' => null]
            : $task->only(self::EDITABLE_FIELDS);
        if ($task !== null) {
            $deadline = $task->getRawOriginal('deadline_at');
            $candidate['deadline_at'] = $deadline === null ? null
                : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $deadline, 'UTC');
        }
        $candidate = array_replace($candidate, $this->editableValues($values));
        if ($task !== null) {
            $resolved = $this->estimates->resolve($task);
            if ($resolved['has_subtasks']) {
                if (array_key_exists('estimated_minutes', $values)
                    && $values['estimated_minutes'] !== $resolved['estimated_minutes']) {
                    throw ValidationException::withMessages([
                        'estimated_minutes' => ['Оцінка завдання обчислюється з активних підзавдань і не може бути змінена окремо.'],
                    ]);
                }
                $candidate['estimated_minutes'] = $resolved['estimated_minutes'];
            }
        }
        $this->validateCandidate($user, $candidate, $task, $lock);

        return $candidate;
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

    private function validateCandidate(User $user, array $candidate, ?Task $task = null, bool $lock = true): void
    {
        if ($candidate['subject_id'] !== null
            && $user->subjects()->whereKey($candidate['subject_id'])->when($lock, fn ($query) => $query->lockForUpdate())->first() === null) {
            throw ValidationException::withMessages(['subject_id' => ['Вибраний предмет недоступний.']]);
        }

        if ($task !== null && $candidate['deadline_at'] !== null
            && $task->subtasks()->whereNotNull('deadline_at')->where('deadline_at', '>', $candidate['deadline_at'])
                ->when($lock, fn ($query) => $query->lockForUpdate())->first() !== null) {
            throw ValidationException::withMessages(['deadline_at' => ['Дедлайн завдання не може бути раніше дедлайну наявного підзавдання.']]);
        }
    }
}
