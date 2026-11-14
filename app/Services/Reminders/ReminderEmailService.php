<?php

namespace App\Services\Reminders;

use App\Jobs\SendReminderEmail;
use App\Mail\StudyReminderMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailer;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

class ReminderEmailService
{
    public function __construct(private ReminderDeliveryRules $payloads) {}

    /** Called once by the owner-locked inbox writer, before its transaction commits. */
    public function enqueue(User $owner, DatabaseNotification $notification): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reminder email enqueue requires the delivery transaction.');
        }
        if (! $owner->reminder_email_enabled || ! $owner->hasVerifiedEmail()) {
            return;
        }
        $this->push(new SendReminderEmail($owner->id, $notification->id, $this->recipientFingerprint($owner)));
    }

    public function enqueueAutomatic(User $owner, int $automaticReminderId): void
    {
        $job = new SendReminderEmail($owner->id, '', $this->recipientFingerprint($owner));
        $job->automaticReminderId = $automaticReminderId;
        $this->push($job);
    }

    private function push(SendReminderEmail $job): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reminder email enqueue requires the delivery transaction.');
        }
        $queue = Queue::connection('reminder-email');
        if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== DB::connection()) {
            throw new LogicException('Reminder email requires the same transactional database connection.');
        }
        // Immediate INSERT, not an after-commit callback. InnoDB hides it until commit.
        $id = $queue->push($job->beforeCommit());
        if ($id === null || $id === false) {
            throw new LogicException('Reminder email intent could not be saved.');
        }
    }

    public function send(int $ownerId, string $notificationId, string $recipientFingerprint): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Reminder email must run independently of delivery.');
        }
        $owner = User::find($ownerId);
        if ($owner === null) {
            return;
        }
        $notification = $owner->notifications()->whereKey($notificationId)->first();
        if ($notification === null || $notification->type !== ReminderDeliveryRules::TYPE) {
            return;
        }
        $payload = $this->payloads->stored($notification->data, $notification->reminder_id);
        $mailer = $this->smtpMailer();
        $message = new StudyReminderMail($payload['message']);

        // Fresh autocommit read immediately before SMTP; no network I/O under a DB lock.
        $owner = User::find($ownerId);
        if ($owner === null || ! $owner->reminder_email_enabled || ! $owner->hasVerifiedEmail()
            || ! hash_equals($recipientFingerprint, $this->recipientFingerprint($owner))) {
            return;
        }
        $mailer->to($owner->email)->send($message);
    }

    public function sendAutomatic(int $ownerId, int $id, string $recipientFingerprint): void
    {
        $automatic = app(AutomaticSessionReminderService::class);
        $mailer = $this->smtpMailer();
        $delivery = $automatic->emailDelivery($ownerId, $id);
        if ($delivery === null) {
            return;
        }
        if (! hash_equals($recipientFingerprint, $this->recipientFingerprint($delivery['owner']))
            || $delivery['not_after'] <= CarbonImmutable::now('UTC')) {
            $automatic->finishEmail($ownerId, $id, false);

            return;
        }
        $mailer->to($delivery['owner']->email)->send(new StudyReminderMail($delivery['message'], inboxAvailable: false));
        $automatic->finishEmail($ownerId, $id, true);
    }

    private function smtpMailer(): Mailer
    {
        // Explicit SMTP avoids the content-logging default/failover mailers.
        if (config('mail.mailers.smtp.transport') !== 'smtp') {
            throw new LogicException('Reminder email requires an SMTP transport.');
        }
        $mailer = Mail::mailer('smtp');
        if (! $mailer->getSymfonyTransport() instanceof SmtpTransport) {
            throw new LogicException('Reminder email requires an SMTP transport.');
        }

        return $mailer;
    }

    private function recipientFingerprint(User $owner): string
    {
        return hash('sha256', $owner->email."\0".$owner->reminder_email_version);
    }
}
