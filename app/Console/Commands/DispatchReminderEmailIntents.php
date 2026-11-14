<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderEmailIntentService;
use Illuminate\Console\Command;

class DispatchReminderEmailIntents extends Command
{
    protected $signature = 'reminders:dispatch-email-intents {--limit=100 : Maximum candidates, 1–1000}';

    protected $description = 'Hand off a bounded batch of persisted manual reminder email intents to the queue';

    public function handle(ReminderEmailIntentService $service): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || ! preg_match('/\A[1-9][0-9]{0,3}\z/', $limit) || (int) $limit > 1000) {
            $this->error('The limit must be a positive decimal integer between 1 and 1000.');

            return self::INVALID;
        }
        try {
            $counts = $service->dispatch((int) $limit);
        } catch (\Throwable) {
            $this->error('Reminder email intent dispatch unavailable.');

            return self::FAILURE;
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
