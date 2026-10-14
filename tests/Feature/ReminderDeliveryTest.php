<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ReminderIntegrityException;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderDeliveryRules;
use App\Services\Reminders\ReminderDeliveryService;
use App\Services\Reminders\ReminderRules;
use App\Services\Reminders\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReminderDeliveryTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    #[DataProvider('targetModes')]
    public function test_all_target_modes_deliver_atomically_and_preserve_every_other_table(?string $field, bool $relative): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $target = match ($field) {
            'task_id' => $task->id, 'subtask_id' => $child->id, 'study_session_id' => $session->id, default => null
        };
        $body = $relative ? [$field => $target, 'anchor' => app(ReminderRules::class)->anchorFor($field)->value, 'offset_minutes' => -15]
            : ['trigger_at' => '2026-10-19T08:00:01Z', ...($field === null ? ['message' => '  General message  '] : [$field => $target])];
        $reminder = app(ReminderService::class)->create($owner, $body);
        $trigger = app(ReminderRules::class)->wireTime($reminder->getRawOriginal('trigger_at'));
        $before = $this->snapshot(['reminders', 'notifications']);
        $old = $reminder->getAttributes();
        $this->travelTo(CarbonImmutable::parse($trigger)->addMicroseconds(123456));
        $this->assertSame(['status' => 'sent'], app(ReminderDeliveryService::class)->process($owner->id, $reminder->id));
        $this->assertSame($before, $this->snapshot(['reminders', 'notifications']));
        $fresh = $reminder->fresh();
        $this->assertSame(ReminderStatus::Sent, $fresh->status);
        $this->assertSame(CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'), $fresh->getRawOriginal('sent_at'));
        $notification = $owner->notifications()->sole();
        $this->assertTrue(Str::isUuid($notification->id));
        $this->assertSame(ReminderDeliveryRules::TYPE, $notification->type);
        $this->assertSame($reminder->id, $notification->reminder_id);
        $this->assertSame($reminder->id, $notification->data['reminder_id']);
        $this->assertSame($trigger, $notification->data['trigger_at']);
        $this->assertSame($fresh->getRawOriginal('sent_at'), $notification->getRawOriginal('created_at'));
        $this->assertNull($notification->read_at);
        foreach (['task_id', 'subtask_id', 'study_session_id', 'trigger_at', 'anchor', 'offset_minutes', 'message'] as $key) {
            $this->assertSame($old[$key] ?? null, $fresh->getRawOriginal($key));
        }
        $this->assertArrayNotHasKey('user_id', $notification->data);
        $history = $this->snapshot();
        $this->assertSame(['status' => 'skipped'], app(ReminderDeliveryService::class)->process($owner->id, $reminder->id));
        $this->assertSame($history, $this->snapshot());
    }

    public static function targetModes(): array
    {
        return [[null, false], ['task_id', false], ['subtask_id', false], ['study_session_id', false],
            ['task_id', true], ['subtask_id', true], ['study_session_id', true]];
    }

    public function test_zero_and_positive_session_offsets_and_overdue_planned_sessions_do_not_infer_missed_state(): void
    {
        [$owner, , , $session] = $this->fixture();
        $ids = [];
        foreach ([0, 30] as $offset) {
            $ids[] = app(ReminderService::class)->create($owner, ['study_session_id' => $session->id, 'anchor' => 'session_start', 'offset_minutes' => $offset])->id;
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:30:00.500000Z'));
        $before = $session->fresh()->getAttributes();
        $this->assertSame(['selected' => 2, 'sent' => 2, 'cancelled' => 0, 'skipped' => 0, 'failed' => 0], app(ReminderDeliveryService::class)->dispatchDue());
        $this->assertSame($before, $session->fresh()->getAttributes());
        $this->assertSame(StudySessionStatus::Planned, $session->fresh()->status);
    }

    public function test_not_due_sent_cancelled_missing_and_foreign_candidates_are_skipped(): void
    {
        [$owner] = $this->fixture();
        $later = app(ReminderService::class)->create($owner, ['message' => 'Future', 'trigger_at' => '2026-10-19T08:00:01Z']);
        $sent = Reminder::factory()->for($owner)->create(['status' => ReminderStatus::Sent, 'sent_at' => '2026-10-18 08:00:00']);
        $cancelled = Reminder::factory()->for($owner)->create(['status' => ReminderStatus::Cancelled]);
        $snapshot = $this->snapshot();
        foreach ([$later, $sent, $cancelled] as $row) {
            $this->assertSame(['status' => 'skipped'], app(ReminderDeliveryService::class)->process($owner->id, $row->id));
        }
        $this->assertSame(['status' => 'skipped'], app(ReminderDeliveryService::class)->process(999999, $later->id));
        $this->assertSame(['status' => 'skipped'], app(ReminderDeliveryService::class)->process($owner->id, 999999));
        $this->assertSame(['selected' => 0, 'sent' => 0, 'cancelled' => 0, 'skipped' => 0, 'failed' => 0], app(ReminderDeliveryService::class)->dispatchDue());
        $this->assertSame($snapshot, $this->snapshot());
    }

    #[DataProvider('ineligibleTargets')]
    public function test_structurally_valid_but_ineligible_sources_cancel_with_history(string $scenario): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $field = str_starts_with($scenario, 'session') ? 'study_session_id' : (str_starts_with($scenario, 'child') ? 'subtask_id' : 'task_id');
        $id = match ($field) {
            'task_id' => $task->id, 'subtask_id' => $child->id, default => $session->id
        };
        $reminder = Reminder::factory()->for($owner)->create([$field => $id]);
        match ($scenario) {
            'task_deleted', 'child_parent_deleted', 'session_parent_deleted' => $task->delete(),
            'task_completed', 'child_parent_completed', 'session_parent_completed' => $task->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 07:00:00']),
            'child_deleted', 'session_child_deleted' => $child->delete(),
            'child_completed', 'session_child_completed' => $child->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 07:00:00']),
            'session_cancelled' => $session->update(['status' => StudySessionStatus::Cancelled]),
            'session_missed' => $session->update(['status' => StudySessionStatus::Missed]),
            'session_rescheduled' => $session->update(['status' => StudySessionStatus::Rescheduled]),
            'session_completed' => $session->update(['status' => StudySessionStatus::Completed, 'completed_at' => '2026-10-19 07:00:00']),
        };
        $before = $reminder->fresh()->getAttributes();
        $otherTables = $this->snapshot(['reminders']);
        $this->assertSame(['status' => 'cancelled'], app(ReminderDeliveryService::class)->process($owner->id, $reminder->id));
        $after = $reminder->fresh()->getAttributes();
        $this->assertSame($before, array_replace($after, ['status' => 'scheduled']));
        $this->assertSame($otherTables, $this->snapshot(['reminders']));
    }

    public static function ineligibleTargets(): array
    {
        return array_map(fn ($v) => [$v], ['task_deleted', 'task_completed', 'child_parent_deleted', 'child_parent_completed', 'child_deleted', 'child_completed',
            'session_parent_deleted', 'session_parent_completed', 'session_child_deleted', 'session_child_completed', 'session_cancelled', 'session_missed', 'session_rescheduled', 'session_completed']);
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_candidate_fails_closed_with_exact_rollback_and_batch_continues(string $scenario): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $foreign = Task::factory()->create();
        $data = match ($scenario) {
            'foreign_task' => ['task_id' => $foreign->id], 'foreign_child' => ['subtask_id' => Subtask::factory()->for($foreign)->create()->id],
            'foreign_session' => ['study_session_id' => StudySession::factory()->for($foreign)->create()->id],
            'multiple' => ['task_id' => $task->id, 'subtask_id' => $child->id],
            'wrong_anchor' => ['task_id' => $task->id, 'anchor' => 'session_start', 'offset_minutes' => 0],
            'orphan_relative' => ['anchor' => 'task_deadline', 'offset_minutes' => 0, 'message' => 'Orphan'],
            'bad_session_child', 'bad_session_owner' => ['study_session_id' => $session->id],
            'stale_relative', 'missing_anchor', 'overflow' => ['task_id' => $task->id, 'anchor' => 'task_deadline', 'offset_minutes' => 0],
            default => [],
        };
        if ($scenario === 'bad_session_child') {
            $session->update(['subtask_id' => Subtask::factory()->for($foreign)->create()->id]);
        } elseif ($scenario === 'bad_session_owner') {
            $session->update(['user_id' => $foreign->user_id]);
        } elseif ($scenario === 'missing_anchor') {
            $task->update(['deadline_at' => null]);
        } elseif ($scenario === 'overflow') {
            $task->update(['deadline_at' => '9999-12-31 23:59:59']);
            $data['offset_minutes'] = 1;
        }
        $reminder = Reminder::factory()->for($owner)->create($data);
        $before = $this->snapshot();
        try {
            app(ReminderDeliveryService::class)->process($owner->id, $reminder->id);
            $this->fail('Corrupt source should fail.');
        } catch (ReminderIntegrityException $exception) {
            $this->assertSame('The reminder dependencies could not be processed.', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $valid = Reminder::factory()->for($owner)->create(['message' => 'Valid due']);
        $this->assertSame(['selected' => 2, 'sent' => 1, 'cancelled' => 0, 'skipped' => 0, 'failed' => 1], app(ReminderDeliveryService::class)->dispatchDue());
        $this->assertSame(ReminderStatus::Scheduled, $reminder->fresh()->status);
        $this->assertSame(ReminderStatus::Sent, $valid->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);
    }

    public static function corruptions(): array
    {
        return array_map(fn ($v) => [$v], ['foreign_task', 'foreign_child', 'foreign_session', 'multiple', 'wrong_anchor', 'orphan_relative',
            'bad_session_child', 'bad_session_owner', 'stale_relative', 'missing_anchor', 'overflow', 'empty_general']);
    }

    #[DataProvider('failures')]
    public function test_notification_and_status_write_failures_roll_back_and_can_retry(string $failure): void
    {
        [$owner] = $this->fixture();
        $reminder = Reminder::factory()->for($owner)->create(['message' => 'Due']);
        $snapshot = $this->snapshot();
        if ($failure === 'notification_veto') {
            DatabaseNotification::saving(fn () => false);
        } elseif ($failure === 'notification_throw') {
            DatabaseNotification::created(fn () => throw new RuntimeException('Injected post-insert failure.'));
        } elseif ($failure === 'reminder_veto') {
            Reminder::saving(fn () => false);
        } elseif ($failure === 'reminder_throw') {
            Reminder::updated(fn () => throw new RuntimeException('Injected sent-status failure.'));
        } elseif ($failure === 'sql') {
            DatabaseNotification::creating(function ($notification): void {
                $notification->reminder_id = 999999;
            });
        }
        try {
            try {
                app(ReminderDeliveryService::class)->process($owner->id, $reminder->id);
                $this->fail('Injected failure should roll back.');
            } catch (ReminderIntegrityException) {
                $this->assertSame($snapshot, $this->snapshot());
            }
        } finally {
            DatabaseNotification::flushEventListeners();
            Reminder::flushEventListeners();
        }
        $this->assertSame(['status' => 'sent'], app(ReminderDeliveryService::class)->process($owner->id, $reminder->id));
        $this->assertSame(['status' => 'skipped'], app(ReminderDeliveryService::class)->process($owner->id, $reminder->id));
        $this->assertDatabaseCount('notifications', 1);
    }

    public static function failures(): array
    {
        return [['notification_veto'], ['notification_throw'], ['reminder_veto'], ['reminder_throw'], ['sql']];
    }

    public function test_schema_uniqueness_json_morphs_and_set_null_preserve_history(): void
    {
        [$owner] = $this->fixture();
        $reminder = Reminder::factory()->for($owner)->create(['message' => 'Due']);
        app(ReminderDeliveryService::class)->process($owner->id, $reminder->id);
        $notification = $owner->notifications()->sole();
        $this->assertSame('json', Schema::getColumnType('notifications', 'data', true));
        $this->assertSame('char(36)', Schema::getColumnType('notifications', 'id', true));
        $unique = collect(Schema::getIndexes('notifications'))->firstWhere('name', 'notifications_reminder_id_unique');
        $this->assertTrue($unique['unique']);
        $this->assertSame(['reminder_id'], $unique['columns']);
        $fk = collect(Schema::getForeignKeys('notifications'))->sole();
        $this->assertSame('set null', $fk['on_delete']);
        $this->assertSame('reminders', $fk['foreign_table']);
        $this->assertTrue($notification->notifiable->is($owner));
        $payload = $notification->data;
        $duplicate = $notification->getAttributes();
        $duplicate['id'] = (string) Str::uuid();
        try {
            DB::table('notifications')->insert($duplicate);
            $this->fail('MySQL uniqueness must reject duplicate receipt.');
        } catch (QueryException $exception) {
            $this->assertSame(1062, $exception->errorInfo[1]);
        }
        $this->assertDatabaseCount('notifications', 1);
        $reminder->delete(); // Guarded test of physical FK behavior; there is no public hard-purge API.
        $this->assertNull($notification->fresh()->reminder_id);
        $this->assertSame($payload, $notification->fresh()->data);
        foreach (range(1, 2) as $unused) {
            $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'future_type', 'data' => [], 'reminder_id' => null]);
        }
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_existing_receipt_with_scheduled_source_is_corruption_not_status_repair(): void
    {
        [$owner] = $this->fixture();
        $reminder = Reminder::factory()->for($owner)->create(['message' => 'Due']);
        $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'future_type', 'data' => [], 'reminder_id' => $reminder->id]);
        $snapshot = $this->snapshot();
        $this->assertSame(['selected' => 1, 'sent' => 0, 'cancelled' => 0, 'skipped' => 0, 'failed' => 1], app(ReminderDeliveryService::class)->dispatchDue());
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_bounded_batch_is_ordered_by_trigger_then_id_and_command_output_contains_only_counts(): void
    {
        [$owner] = $this->fixture();
        $late = Reminder::factory()->for($owner)->create(['message' => 'Private late', 'trigger_at' => '2026-10-18 09:00:00']);
        $first = Reminder::factory()->for($owner)->create(['message' => 'Private early', 'trigger_at' => '2026-10-18 08:00:00']);
        $second = Reminder::factory()->for($owner)->create(['message' => 'Private tie', 'trigger_at' => '2026-10-18 08:00:00']);
        $future = Reminder::factory()->for($owner)->create(['message' => 'Future', 'trigger_at' => '2026-10-19 09:00:00']);
        $counts = ['selected' => 2, 'sent' => 2, 'cancelled' => 0, 'skipped' => 0, 'failed' => 0];
        $this->artisan('reminders:dispatch-due', ['--limit' => '2'])->expectsOutput(json_encode($counts))->assertExitCode(0);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], DatabaseNotification::pluck('reminder_id')->all());
        $this->assertSame(ReminderStatus::Scheduled, $late->fresh()->status);
        $this->assertSame(ReminderStatus::Scheduled, $future->fresh()->status);
        $this->assertSame(['selected' => 1, 'sent' => 1, 'cancelled' => 0, 'skipped' => 0, 'failed' => 0], app(ReminderDeliveryService::class)->dispatchDue(1));
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_command_isolates_failure_reports_nonzero_and_scheduler_runs_every_minute(): void
    {
        [$owner] = $this->fixture();
        Reminder::factory()->for($owner)->create(); // Invalid general message.
        Reminder::factory()->for($owner)->create(['message' => 'Valid']);
        $this->artisan('reminders:dispatch-due')->expectsOutput('{"selected":2,"sent":1,"cancelled":0,"skipped":0,"failed":1}')->assertExitCode(1);
        foreach (['0', '-1', '1001', '1.0', 'true', ' 1', '01'] as $limit) {
            $this->artisan('reminders:dispatch-due', ['--limit' => $limit])->assertExitCode(2);
        }
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'reminders:dispatch-due'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertStringContainsString('--limit=100', $event->command);
    }

    public function test_actual_transaction_lock_order_and_ambient_transactions(): void
    {
        [$owner, , , $session] = $this->fixture();
        $reminder = Reminder::factory()->for($owner)->create(['study_session_id' => $session->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(ReminderDeliveryService::class)->process($owner->id, $reminder->id);
            $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'for update'))->values()->all();
            $tables = array_map(function ($sql) {
                preg_match('/from `([^`]+)`/', $sql, $match);

                return $match[1];
            }, $queries);
            $this->assertSame(['users', 'tasks', 'subtasks', 'study_sessions', 'reminders'], $tables);
        } finally {
            DB::disableQueryLog();
        }
        DB::beginTransaction();
        try {
            $this->expectException(LogicException::class);
            app(ReminderDeliveryService::class)->dispatchDue();
        } finally {
            DB::rollBack();
        }
    }

    #[DataProvider('calendarDueTimes')]
    public function test_relative_delivery_uses_utc_across_kyiv_dst_and_iso_year_boundaries(string $now, string $anchor, int $offset, string $due): void
    {
        [$owner, $task, $child] = $this->fixture();
        $child->update(['deadline_at' => null]);
        $task->update(['deadline_at' => str_replace(['T', 'Z'], [' ', ''], $anchor)]);
        $this->travelTo(CarbonImmutable::parse($now));
        $reminder = app(ReminderService::class)->create($owner, ['task_id' => $task->id, 'anchor' => 'task_deadline', 'offset_minutes' => $offset]);
        $this->travelTo(CarbonImmutable::parse($due)->addMicroseconds(1));
        $this->assertSame(['status' => 'sent'], app(ReminderDeliveryService::class)->process($owner->id, $reminder->id));
        $notification = $owner->notifications()->sole();
        $this->assertSame($due, $notification->data['trigger_at']);
        $this->assertSame(str_replace(['T', 'Z'], [' ', ''], $due), $reminder->fresh()->getRawOriginal('sent_at'));
        $this->assertSame($reminder->fresh()->getRawOriginal('sent_at'), $notification->getRawOriginal('created_at'));
    }

    public static function calendarDueTimes(): array
    {
        return [['2026-03-28T00:00:00Z', '2026-03-29T00:30:00Z', 60, '2026-03-29T01:30:00Z'],
            ['2026-10-24T00:00:00Z', '2026-10-25T00:30:00Z', 60, '2026-10-25T01:30:00Z'],
            ['2026-12-30T00:00:00Z', '2026-12-31T23:45:00Z', 30, '2027-01-01T00:15:00Z']];
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($owner)->create(['deadline_at' => '2026-10-21 12:00:00', 'estimated_minutes' => 120]);
        $child = Subtask::factory()->for($task)->create(['deadline_at' => '2026-10-20 12:00:00', 'estimated_minutes' => 120]);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);

        return [$owner, $task, $child, $session];
    }

    private function snapshot(array $except = []): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (! in_array($table, $except, true)) {
                $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
                usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                $result[$table] = $rows;
            }
        }
        ksort($result);

        return $result;
    }
}
