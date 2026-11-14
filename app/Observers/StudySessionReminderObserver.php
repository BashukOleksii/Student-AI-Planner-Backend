<?php

namespace App\Observers;

use App\Models\StudySession;
use App\Services\Reminders\AutomaticSessionReminderService;

class StudySessionReminderObserver
{
    public function created(StudySession $session): void
    {
        app(AutomaticSessionReminderService::class)->schedule($session);
    }

    public function updated(StudySession $session): void
    {
        if ($session->status->value !== 'planned' || $session->wasChanged(['starts_at', 'ends_at'])) {
            app(AutomaticSessionReminderService::class)->cancelForSession($session);
        }
    }
}
