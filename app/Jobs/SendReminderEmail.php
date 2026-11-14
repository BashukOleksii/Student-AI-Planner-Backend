<?php

namespace App\Jobs;

use App\Services\Reminders\ReminderEmailService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SendReminderEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    // Explicit default also keeps serialized jobs from before this extension readable.
    public ?int $automaticReminderId = null;

    public function __construct(public int $ownerId, public string $notificationId, public string $recipientFingerprint) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ReminderEmailService $service): void
    {
        try {
            if ($this->automaticReminderId !== null) {
                $service->sendAutomatic($this->ownerId, $this->automaticReminderId, $this->recipientFingerprint);
            } else {
                $service->send($this->ownerId, $this->notificationId, $this->recipientFingerprint);
            }

            return;
        } catch (Throwable) {
            // Never pass SMTP/SQL details, message content or secrets to worker logs/failed_jobs.
        }

        throw new RuntimeException('Reminder email delivery failed.');
    }
}
