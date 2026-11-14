<?php

namespace App\Services\Reminders;

use App\Models\AutomaticSessionReminder;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;
use UnexpectedValueException;

/** Automatic session-only delivery; manual Reminder rows and recovery sources stay independent. */
class AutomaticSessionReminderService
{
    public const TYPE = 'automatic_session_reminder';

    public const MESSAGE = 'Незабаром розпочнеться запланована навчальна сесія.';

    public function __construct(private ReminderTargetService $targets, private ReminderEmailService $email, private ReminderRules $rules) {}

    /** Synchronous created observer: current application writers already hold the owner transaction. */
    public function schedule(StudySession $session): void
    {
        DB::transaction(function () use ($session): void {
            $owner = User::whereKey($session->user_id)->lockForUpdate()->firstOrFail();
            if (! $this->channelEnabled($owner, 'in_app') && ! $this->channelEnabled($owner, 'email')) {
                return;
            }
            $context = $this->targets->resolve($owner, ['study_session_id' => $session->id], true, false);
            $now = CarbonImmutable::now('UTC');
            $start = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $context['session']->getRawOriginal('starts_at'), 'UTC');
            $trigger = $start->subMinutes($owner->session_reminder_lead_minutes);
            if (! $this->targets->eligible($context, requireFutureSession: false) || $trigger <= $now) {
                return;
            }
            foreach (['in_app', 'email'] as $channel) {
                if ($this->channelEnabled($owner, $channel)) {
                    AutomaticSessionReminder::firstOrCreate(['study_session_id' => $session->id, 'channel' => $channel], [
                        'user_id' => $owner->id, 'lead_minutes' => $owner->session_reminder_lead_minutes,
                        'session_starts_at' => $start->format('Y-m-d H:i:s'), 'trigger_at' => $trigger->format('Y-m-d H:i:s'),
                        'status' => 'scheduled',
                    ]);
                }
            }
        });
    }

    public function cancelForSession(StudySession $session): void
    {
        AutomaticSessionReminder::where('user_id', $session->user_id)->where('study_session_id', $session->id)
            ->whereIn('status', ['scheduled', 'queued'])->update(['status' => 'cancelled']);
    }

    /** Profile writer owns the User lock. Re-enabling never resurrects cancelled records. */
    public function cancelDisabledChannels(User $owner): void
    {
        foreach (['in_app', 'email'] as $channel) {
            if (! $this->channelEnabled($owner, $channel)) {
                AutomaticSessionReminder::where('user_id', $owner->id)->where('channel', $channel)
                    ->whereIn('status', ['scheduled', 'queued'])->update(['status' => 'cancelled']);
            }
        }
    }

    public function dispatchDue(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000 || DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('Expected an outermost bounded automatic reminder dispatch.');
        }
        $rows = AutomaticSessionReminder::where('status', 'scheduled')
            ->where('trigger_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'))
            ->orderBy('trigger_at')->orderBy('id')->limit($limit)->get(['id', 'user_id']);
        $counts = ['selected' => $rows->count(), 'delivered' => 0, 'queued' => 0, 'cancelled' => 0, 'suppressed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($rows as $candidate) {
            try {
                $result = DB::transaction(function () use ($candidate): string {
                    $owner = User::whereKey($candidate->user_id)->lockForUpdate()->first();
                    $source = AutomaticSessionReminder::where('user_id', $owner?->id)->find($candidate->id);
                    if ($source === null || $source->status !== 'scheduled') {
                        return 'skipped';
                    }
                    // Shared target validation follows the application's owner/work/session lock order.
                    $context = $this->targets->resolve($owner, ['study_session_id' => $source->study_session_id], true, false);
                    $row = AutomaticSessionReminder::whereKey($source->id)->lockForUpdate()->firstOrFail();
                    $now = CarbonImmutable::now('UTC');
                    if ($row->trigger_at > $now) {
                        return 'skipped';
                    }
                    if (! $this->valid($owner, $row, $context, $now)) {
                        $row->update(['status' => 'cancelled']);

                        return 'cancelled';
                    }
                    if ($this->manualCollision($owner, $row)) {
                        $row->update(['status' => 'suppressed']);

                        return 'suppressed';
                    }
                    if ($row->channel === 'email') {
                        $this->email->enqueueAutomatic($owner, $row->id);
                        $row->update(['status' => 'queued']);
                        $this->assertBeforeStart($row);

                        return 'queued';
                    }
                    $notification = $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => self::TYPE,
                        'reminder_id' => null, 'data' => $this->payload($row), 'read_at' => null]);
                    $row->update(['status' => 'delivered', 'notification_id' => $notification->id]);
                    $this->assertBeforeStart($row);

                    return 'delivered';
                });
                $counts[$result]++;
            } catch (Throwable) {
                // Only counts leave the dispatcher; the candidate transaction rolled back.
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /** Fresh, nontransactional check immediately before SMTP, also on every retry. */
    public function emailDelivery(int $ownerId, int $id): ?array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Automatic reminder SMTP must run outside a transaction.');
        }
        $owner = User::find($ownerId);
        $row = AutomaticSessionReminder::where('user_id', $ownerId)->where('channel', 'email')->find($id);
        if ($owner === null || $row === null || $row->status !== 'queued') {
            return null;
        }
        $context = $this->targets->resolve($owner, ['study_session_id' => $row->study_session_id], false, false);
        if (! $this->valid($owner, $row, $context, CarbonImmutable::now('UTC'))) {
            $row->update(['status' => 'cancelled']);

            return null;
        }
        if ($this->manualCollision($owner, $row)) {
            $row->update(['status' => 'suppressed']);

            return null;
        }

        return ['owner' => $owner, 'message' => self::MESSAGE, 'not_after' => $row->session_starts_at];
    }

    public function finishEmail(int $ownerId, int $id, bool $delivered): void
    {
        AutomaticSessionReminder::where('user_id', $ownerId)->whereKey($id)->where('channel', 'email')
            ->where('status', 'queued')->update(['status' => $delivered ? 'delivered' : 'cancelled']);
    }

    private function valid(User $owner, AutomaticSessionReminder $row, array $context, CarbonImmutable $now): bool
    {
        return $this->channelEnabled($owner, $row->channel)
            && $this->targets->eligible($context, requireFutureSession: false)
            && $context['session']->getRawOriginal('starts_at') === $row->getRawOriginal('session_starts_at')
            && $row->session_starts_at > $now;
    }

    private function assertBeforeStart(AutomaticSessionReminder $row): void
    {
        if ($row->session_starts_at <= CarbonImmutable::now('UTC')) {
            // Roll back a slow inbox/job write; a later dispatch will cancel the expired intent.
            throw new LogicException('Automatic reminder session already started.');
        }
    }

    private function channelEnabled(User $owner, string $channel): bool
    {
        return $channel === 'in_app' ? $owner->session_reminder_in_app_enabled
            : ($owner->session_reminder_email_enabled && $owner->reminder_email_enabled && $owner->hasVerifiedEmail());
    }

    private function manualCollision(User $owner, AutomaticSessionReminder $row): bool
    {
        // A manual reminder owns inbox and, with current general consent, its optional email channel.
        return Reminder::where('user_id', $owner->id)->where('study_session_id', $row->study_session_id)
            ->where('trigger_at', $row->getRawOriginal('trigger_at'))->whereIn('status', ['scheduled', 'sent'])->exists();
    }

    private function payload(AutomaticSessionReminder $row): array
    {
        return ['reminder_id' => null, 'message' => self::MESSAGE, 'task_id' => null, 'subtask_id' => null,
            'study_session_id' => $row->study_session_id, 'trigger_at' => $row->trigger_at->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    /** Immutable inbox snapshot, independent of later session deletion or mutation. */
    public function stored(mixed $data): array
    {
        $fields = ['reminder_id', 'message', 'task_id', 'subtask_id', 'study_session_id', 'trigger_at'];
        if (! is_array($data) || count($data) !== count($fields) || array_diff($fields, array_keys($data)) !== []
            || $data['reminder_id'] !== null || $data['message'] !== self::MESSAGE || $data['task_id'] !== null
            || $data['subtask_id'] !== null || ! is_int($data['study_session_id']) || $data['study_session_id'] < 1) {
            throw new UnexpectedValueException('Invalid automatic reminder history.');
        }
        $this->rules->timestamp($data['trigger_at']);

        return $data;
    }
}
