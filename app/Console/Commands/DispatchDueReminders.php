<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDeliveryService;
use Illuminate\Console\Command;

class DispatchDueReminders extends Command
{
    protected $signature = 'reminders:dispatch-due {--limit=100 : Maximum candidates, 1–1000}';

    protected $description = 'Deliver a bounded batch of due reminders to the in-app inbox';

    public function handle(ReminderDeliveryService $service): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || ! preg_match('/\A[1-9][0-9]{0,3}\z/', $limit) || (int) $limit > 1000) {
            $this->error('The limit must be a positive decimal integer between 1 and 1000.');

            return self::INVALID;
        }
        $counts = $service->dispatchDue((int) $limit);
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
