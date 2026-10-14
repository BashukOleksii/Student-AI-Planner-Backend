<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

class ReminderService
{
    public function __construct(private ReminderRules $rules, private ReminderDependencyService $dependencies) {}

    public function listing(User $owner, array $filters): LengthAwarePaginator
    {
        $query = $owner->reminders()->orderBy('trigger_at')->orderBy('id');
        foreach (['status', 'trigger_from', 'trigger_to'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field === 'status' ? 'status' : 'trigger_at', match ($field) {
                    'trigger_from' => '>=', 'trigger_to' => '<=', default => '=',
                }, $field === 'status' ? $filters[$field] : $this->databaseTime($filters[$field]));
            }
        }
        $page = $query->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        foreach ($page->items() as $reminder) {
            $this->integrity($owner, $reminder);
        }
        $page->appends(array_diff_key($filters, ['page' => true]));

        return $page;
    }

    public function find(User $owner, string $id): Reminder
    {
        $reminder = $owner->reminders()->whereKey($id)->firstOrFail();
        Gate::forUser($owner)->authorize('view', $reminder);
        $this->integrity($owner, $reminder);

        return $reminder;
    }

    public function create(User $user, array $data): Reminder
    {
        $this->validate($data, true);

        return $this->transaction($user, function (User $owner) use ($data): Reminder {
            $session = $this->target($owner, $data, true, true);
            $this->future($data['trigger_at']);
            $reminder = new Reminder(['user_id' => $owner->id, ...$data, 'anchor' => null,
                'offset_minutes' => null, 'status' => ReminderStatus::Scheduled, 'sent_at' => null]);
            $reminder->trigger_at = $this->databaseTime($data['trigger_at']);
            $this->save($reminder);
            $this->future($data['trigger_at']);
            if ($session !== null && ! $this->futureSession($session)) {
                throw ValidationException::withMessages(['target' => 'The reminder target is unavailable.']);
            }

            return $reminder;
        });
    }

    public function update(User $user, string $id, array $data): Reminder
    {
        $this->validate($data, false);

        return $this->mutate($user, $id, function (Reminder $reminder) use ($data): void {
            $this->rules->editable($reminder->status, $reminder->anchor === null && $reminder->offset_minutes === null);
            $trigger = $data['trigger_at'] ?? $this->wireTime($reminder);
            $this->future($trigger);
            if ($this->rules->targets($reminder->getAttributes()) === []
                && ! $this->rules->meaningful(array_key_exists('message', $data) ? $data['message'] : $reminder->message)) {
                throw ValidationException::withMessages(['message' => 'A general reminder requires a meaningful message.']);
            }
            $reminder->fill($data);
            $reminder->trigger_at = $this->databaseTime($trigger);
            $this->save($reminder);
            $this->future($trigger);
        });
    }

    public function cancel(User $user, string $id): Reminder
    {
        return $this->mutate($user, $id, function (Reminder $reminder): void {
            $this->rules->editable($reminder->status);
            $reminder->status = ReminderStatus::Cancelled;
            $reminder->sent_at = null;
            $this->save($reminder);
        });
    }

    private function mutate(User $user, string $id, Closure $action): Reminder
    {
        return $this->transaction($user, function (User $owner) use ($id, $action): Reminder {
            // Read only after the owner wait. Work must be locked before the Reminder.
            $source = $owner->reminders()->whereKey($id)->firstOrFail();
            Gate::forUser($owner)->authorize('update', $source);
            if ($source->status === ReminderStatus::Scheduled) {
                $this->integrity($owner, $source, true);
            }
            $reminder = $owner->reminders()->whereKey($id)->lockForUpdate()->firstOrFail();
            $action($reminder);

            return $reminder;
        });
    }

    private function transaction(User $user, Closure $action): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Reminder mutation requires an outermost transaction.');
        }

        return DB::transaction(function () use ($user, $action): mixed {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            return $action($owner);
        });
    }

    private function integrity(User $owner, Reminder $reminder, bool $lock = false): void
    {
        if ($reminder->status !== ReminderStatus::Scheduled) {
            return; // Historical targets are preserved without repair or eligibility checks.
        }
        $targets = $this->rules->targets($reminder->getAttributes());
        if ($targets === []) {
            if ($reminder->anchor !== null || $reminder->offset_minutes !== null || ! $this->rules->meaningful($reminder->message)) {
                throw new UnexpectedValueException('Invalid reminder relationships.');
            }
        } elseif (! $this->dependencies->compatible($reminder, $owner)) {
            throw new UnexpectedValueException('Invalid reminder relationships.');
        }
        $this->target($owner, $targets, $lock, false);
    }

    /** Structural ownership checks also apply to existing scheduled records, without F2 reconciliation. */
    private function target(User $owner, array $data, bool $lock, bool $create): ?StudySession
    {
        $targets = $this->rules->targets($data);
        if ($targets === []) {
            return null;
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
            && ($field !== 'study_session_id' || ($session !== null && $session->task_id === $task->id && $session->subtask_id === $childId));
        if ($create && $valid) {
            $valid = ! $task->trashed() && $task->status === TaskStatus::Pending
                && ($child === null || (! $child->trashed() && $child->status === TaskStatus::Pending))
                && ($session === null || ($session->status === StudySessionStatus::Planned
                    && $this->futureSession($session)));
        }
        if (! $valid) {
            if ($create) {
                throw ValidationException::withMessages(['target' => 'The reminder target is unavailable.']);
            }
            throw new UnexpectedValueException('Invalid reminder relationships.');
        }

        return $session;
    }

    private function futureSession(StudySession $session): bool
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $session->getRawOriginal('starts_at'), 'UTC') > CarbonImmutable::now('UTC');
    }

    private function validate(array $data, bool $create): void
    {
        if (($errors = $this->rules->errors($data, $create)) !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function future(string $trigger): void
    {
        try {
            $this->rules->future($trigger, CarbonImmutable::now('UTC'));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['trigger_at' => 'The trigger must be strictly in the future.']);
        }
    }

    private function databaseTime(string $trigger): string
    {
        return CarbonImmutable::createFromTimestampUTC($this->rules->timestamp($trigger))->format('Y-m-d H:i:s');
    }

    private function wireTime(Reminder $reminder): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $reminder->getRawOriginal('trigger_at'), 'UTC')->format('Y-m-d\TH:i:s\Z');
    }

    private function save(Reminder $reminder): void
    {
        if (! $reminder->save()) {
            throw new LogicException('The reminder could not be saved.');
        }
    }
}
