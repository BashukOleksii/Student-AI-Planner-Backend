<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Exceptions\ReminderIntegrityException;
use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

class ReminderDeliveryService
{
    public function __construct(
        private ReminderRules $rules,
        private ReminderDeliveryRules $delivery,
        private ReminderTargetService $targets,
    ) {}

    public function dispatchDue(int $limit = 100): array
    {
        $this->outermost();
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The batch limit must be between 1 and 1000.');
        }
        $now = CarbonImmutable::now('UTC');
        $candidates = Reminder::where('status', ReminderStatus::Scheduled)
            ->where('trigger_at', '<=', $now->format('Y-m-d H:i:s.u'))->orderBy('trigger_at')->orderBy('id')
            ->limit($limit)->get(['id', 'user_id']);
        $counts = ['selected' => $candidates->count(), 'sent' => 0, 'cancelled' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($candidates as $candidate) {
            try {
                $counts[$this->process($candidate->user_id, $candidate->id)['status']]++;
            } catch (Throwable) {
                // Each transaction has rolled back; no source contents/SQL are emitted by the command.
                $counts['failed']++;
            }
        }

        return $counts;
    }

    public function process(int $ownerId, int $reminderId): array
    {
        $this->outermost();
        try {
            return DB::transaction(function () use ($ownerId, $reminderId): array {
                // A current owner read precedes every RR snapshot and observes prior writers after a wait.
                $owner = User::whereKey($ownerId)->lockForUpdate()->first();
                $source = $owner?->reminders()->whereKey($reminderId)->first();
                if ($source === null || $source->status !== ReminderStatus::Scheduled) {
                    return ['status' => 'skipped'];
                }
                $context = $this->targets->forReminder($owner, $source, true);
                $reminder = $owner->reminders()->whereKey($reminderId)->lockForUpdate()->first();
                $now = CarbonImmutable::now('UTC');
                if ($reminder === null || $reminder->status !== ReminderStatus::Scheduled) {
                    return ['status' => 'skipped'];
                }
                if ($source->only(ReminderRules::TARGETS) !== $reminder->only(ReminderRules::TARGETS) || $source->anchor !== $reminder->anchor) {
                    throw new ReminderIntegrityException;
                }
                $trigger = $this->rules->wireTime($reminder->getRawOriginal('trigger_at'));
                if (! $this->delivery->due($trigger, $now)) {
                    return ['status' => 'skipped'];
                }
                // A scheduled row with any existing receipt is corruption, never a reason to repair status.
                if (DatabaseNotification::where('reminder_id', $reminderId)->exists()) {
                    throw new ReminderIntegrityException;
                }
                if (! $this->targets->eligible($context, requireFutureSession: false)) {
                    if (! $reminder->update(['status' => ReminderStatus::Cancelled, 'sent_at' => null])) {
                        throw new ReminderIntegrityException;
                    }

                    return ['status' => 'cancelled'];
                }
                if ($reminder->anchor !== null) {
                    $expected = $this->rules->resolve($this->targets->anchor($context), $reminder->offset_minutes);
                    if ($expected !== $trigger) {
                        throw new ReminderIntegrityException;
                    }
                }
                $payload = $this->delivery->payload($reminder->id, $reminder->only(ReminderRules::TARGETS), $reminder->message, $trigger);
                // Native Notifiable relation + DatabaseNotification model on this same connection/transaction.
                $timestamp = $now->format('Y-m-d H:i:s');
                $notification = $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => ReminderDeliveryRules::TYPE,
                    'data' => $payload, 'read_at' => null, 'reminder_id' => $reminder->id,
                    'created_at' => $timestamp, 'updated_at' => $timestamp]);
                if (! $notification->exists || ! $reminder->update(['status' => ReminderStatus::Sent, 'sent_at' => $timestamp])) {
                    throw new ReminderIntegrityException;
                }

                return ['status' => 'sent'];
            });
        } catch (Throwable $exception) {
            throw $exception instanceof ReminderIntegrityException ? $exception : new ReminderIntegrityException($exception);
        }
    }

    private function outermost(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Reminder delivery requires an outermost transaction.');
        }
    }
}
