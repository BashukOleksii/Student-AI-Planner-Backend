<?php

namespace App\Services\Reminders;

use App\Jobs\SendReminderEmail;
use Carbon\CarbonImmutable;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use Throwable;

class ReminderEmailIntentService
{
    public function dispatch(int $limit = 100): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Email intent dispatch requires an outermost transaction.');
        }
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The batch limit must be between 1 and 1000.');
        }
        $ids = DB::table('reminder_email_intents')->whereNull('queued_at')
            ->where('next_attempt_at', '<=', CarbonImmutable::now('UTC'))
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        $counts = ['selected' => $ids->count(), 'queued' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            try {
                $result = DB::transaction(function () use ($id): string {
                    $intent = DB::table('reminder_email_intents')->where('id', $id)->lockForUpdate()->first();
                    if ($intent === null || $intent->queued_at !== null
                        || $intent->next_attempt_at > CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')) {
                        return 'skipped';
                    }
                    $queue = Queue::connection('reminder-email');
                    if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== DB::connection()
                        || DB::connection()->getDriverName() !== 'mysql'
                        || config('queue.connections.reminder-email.table') !== 'jobs'
                        || config('queue.connections.reminder-email.queue') !== 'reminder-email'
                        || config('queue.connections.reminder-email.after_commit') !== false) {
                        throw new LogicException('Email intent handoff requires the domain MySQL queue.');
                    }
                    // Check actual engines, not just configuration: both writes must roll back together.
                    $tables = array_map(fn ($table) => DB::connection()->getTablePrefix().$table, ['jobs', 'reminder_email_intents']);
                    $engines = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                        ->whereIn('TABLE_NAME', $tables)->pluck('ENGINE');
                    if ($engines->count() !== 2 || $engines->contains(fn ($engine) => strcasecmp($engine, 'InnoDB') !== 0)) {
                        throw new LogicException('Email intent handoff requires InnoDB tables.');
                    }
                    $job = new SendReminderEmail($intent->user_id, $intent->notification_id, $intent->recipient_fingerprint);
                    $jobId = $queue->push($job->beforeCommit(), '', 'reminder-email');
                    if ($jobId === null || $jobId === false) {
                        throw new LogicException('Email intent handoff failed.');
                    }
                    DB::table('reminder_email_intents')->where('id', $id)->update([
                        'queued_at' => CarbonImmutable::now('UTC'), 'updated_at' => CarbonImmutable::now('UTC'),
                        'attempts' => $intent->attempts + 1,
                    ]);

                    return 'queued';
                });
                $counts[$result]++;
            } catch (Throwable) {
                // The job INSERT and queued marker have rolled back. Do not expose SQL/payloads.
                $counts['failed']++;
                try {
                    DB::transaction(function () use ($id): void {
                        $intent = DB::table('reminder_email_intents')->where('id', $id)->lockForUpdate()->first();
                        if ($intent === null || $intent->queued_at !== null
                            || $intent->next_attempt_at > CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')) {
                            return;
                        }
                        $delay = [60, 300, 900, 3600][min($intent->attempts, 3)];
                        DB::table('reminder_email_intents')->where('id', $id)->update([
                            'attempts' => $intent->attempts + 1,
                            'next_attempt_at' => CarbonImmutable::now('UTC')->addSeconds($delay),
                            'updated_at' => CarbonImmutable::now('UTC'),
                        ]);
                    });
                } catch (Throwable) {
                    Log::warning('Reminder email intent retry scheduling failed.', ['intent_id' => $id]);
                }
                Log::warning('Reminder email intent handoff failed.', ['intent_id' => $id]);
            }
        }

        return $counts;
    }
}
