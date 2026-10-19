<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/** Internal shared writer: caller owns the outermost transaction and acquires owner before any snapshot. */
class ReminderMutationService
{
    public function __construct(private ReminderRules $rules, private ReminderTargetService $targets) {}

    public function create(User $user, array $data): Reminder
    {
        $this->validate($data, true);

        return $this->transaction($user, function (User $owner) use ($data): Reminder {
            ['context' => $context, 'trigger' => $trigger] = $this->prepareCreate($owner, $data, true);
            $reminder = new Reminder(['user_id' => $owner->id, ...$data, 'anchor' => $data['anchor'] ?? null,
                'offset_minutes' => $data['offset_minutes'] ?? null, 'status' => ReminderStatus::Scheduled, 'sent_at' => null]);
            $reminder->trigger_at = $this->databaseTime($trigger);
            $this->save($reminder);
            $this->future($trigger);
            if (! $this->targets->eligible($context)) {
                throw ValidationException::withMessages(['target' => 'The reminder target is unavailable.']);
            }

            return $reminder;
        });
    }

    public function update(User $user, string $id, array $data): Reminder
    {
        $this->validate($data, false);

        return $this->mutate($user, $id, function (Reminder $reminder, ?array $context) use ($data): void {
            $trigger = $this->prepareUpdate($reminder, $data, $context);
            $reminder->fill($data);
            $reminder->trigger_at = $this->databaseTime($trigger);
            $this->save($reminder);
            $this->future($trigger);
            if ($reminder->anchor !== null && array_key_exists('offset_minutes', $data) && ! $this->targets->eligible($context)) {
                throw ValidationException::withMessages(['target' => 'The reminder target is unavailable.']);
            }
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

    /** Read-only creation preparation, shared with the writer. No record is saved or identity allocated. */
    public function prepareCreate(User $owner, array $data, bool $lock = false): array
    {
        $this->validate($data, true);
        $context = $this->targets->resolve($owner, $data, $lock, true);
        $trigger = isset($data['anchor']) ? $this->relativeTrigger($context, $data['offset_minutes']) : $data['trigger_at'];
        $this->future($trigger);

        return compact('context', 'trigger');
    }

    /** Context is structurally validated through ReminderTargetService before this read-only candidate check. */
    public function prepareUpdate(Reminder $reminder, array $data, ?array $context): string
    {
        $this->rules->editable($reminder->status);
        $relative = $reminder->anchor !== null;
        $this->validate($data, false, $relative);
        if ($relative && array_key_exists('offset_minutes', $data)) {
            if (! $this->targets->eligible($context)) {
                throw ValidationException::withMessages(['target' => 'The reminder target is unavailable.']);
            }
            $trigger = $this->relativeTrigger($context, $data['offset_minutes']);
        } else {
            $trigger = $data['trigger_at'] ?? $this->wireTime($reminder);
        }
        $this->future($trigger);
        if ($this->rules->targets($reminder->getAttributes()) === []
            && ! $this->rules->meaningful(array_key_exists('message', $data) ? $data['message'] : $reminder->message)) {
            throw ValidationException::withMessages(['message' => 'A general reminder requires a meaningful message.']);
        }

        return $trigger;
    }

    private function mutate(User $user, string $id, Closure $action): Reminder
    {
        return $this->transaction($user, function (User $owner) use ($id, $action): Reminder {
            // Read only after the owner wait. Work must be locked before the Reminder.
            $source = $owner->reminders()->whereKey($id)->firstOrFail();
            Gate::forUser($owner)->authorize('update', $source);
            $context = $source->status === ReminderStatus::Scheduled ? $this->integrity($owner, $source, true) : null;
            $reminder = $owner->reminders()->whereKey($id)->lockForUpdate()->firstOrFail();
            $action($reminder, $context);

            return $reminder;
        });
    }

    private function transaction(User $user, Closure $action): mixed
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reminder mutation core requires an existing owner-first transaction.');
        }
        $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

        return $action($owner);
    }

    private function integrity(User $owner, Reminder $reminder, bool $lock = false): ?array
    {
        if ($reminder->status !== ReminderStatus::Scheduled) {
            return null; // Historical targets are preserved without repair or eligibility checks.
        }

        return $this->targets->forReminder($owner, $reminder, $lock);
    }

    private function relativeTrigger(array $context, mixed $offset): string
    {
        $anchor = $this->targets->anchor($context);
        try {
            return $this->rules->resolve($anchor, $offset);
        } catch (InvalidArgumentException|\OverflowException) {
            throw ValidationException::withMessages(['anchor' => 'The relative trigger cannot be resolved.']);
        }
    }

    private function validate(array $data, bool $create, ?bool $relative = null): void
    {
        if (($errors = $this->rules->errors($data, $create, $relative)) !== []) {
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
        return $this->rules->databaseTime($trigger);
    }

    private function wireTime(Reminder $reminder): string
    {
        return $this->rules->wireTime($reminder->getRawOriginal('trigger_at'));
    }

    private function save(Reminder $reminder): void
    {
        if (! $reminder->save()) {
            throw new LogicException('The reminder could not be saved.');
        }
    }
}
