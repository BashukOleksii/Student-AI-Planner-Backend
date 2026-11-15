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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('integration')]
#[Group('mysql')]
class CompactReminderDeliveryTest extends TestCase
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
            'task_id' => $task->id,
            'subtask_id' => $child->id,
            'study_session_id' => $session->id,
            default => null,
        };
        $body = $relative ? [$field => $target, 'anchor' => app(ReminderRules::class)->anchorFor($field)->value, 'offset_minutes' => -15] : ['trigger_at' => '2026-10-19T08:00:01Z', ...$field === null ? ['message' => '  General message  '] : [$field => $target]];
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

    public function test_missed_parent_keeps_task_child_and_session_reminders_eligible_and_preserves_sent_inbox_on_completion(): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $service = app(ReminderService::class);
        $reminders = [];
        foreach (['task_id' => $task->id, 'subtask_id' => $child->id, 'study_session_id' => $session->id] as $field => $id) {
            $reminders[] = $service->create($owner, [$field => $id, 'trigger_at' => '2026-10-19T08:00:02Z']);
        }
        $this->actingAs($owner, 'web')->postJson('/api/tasks/'.$task->id.'/miss')->assertOk();
        foreach ($reminders as $reminder) {
            $this->assertSame(ReminderStatus::Scheduled, $reminder->fresh()->status);
        }
        $relative = $service->create($owner, ['task_id' => $task->id, 'anchor' => 'task_deadline', 'offset_minutes' => -15]);
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-22T12:00:00Z'])->assertOk();
        $this->assertSame(ReminderStatus::Scheduled, $relative->fresh()->status);
        $this->assertSame('2026-10-22 11:45:00', $relative->fresh()->getRawOriginal('trigger_at'));
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:03Z'));
        foreach ($reminders as $reminder) {
            $this->assertSame(['status' => 'sent'], app(ReminderDeliveryService::class)->process($owner->id, $reminder->id));
        }
        $inbox = $owner->notifications()->orderBy('id')->get()->map->getAttributes()->all();
        $this->assertCount(3, $inbox);
        $this->postJson('/api/tasks/'.$task->id.'/complete', $this->getJson('/api/tasks/'.$task->id.'/completion-review')->assertOk()->json())->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(ReminderStatus::Cancelled, $relative->fresh()->status);
        $this->assertSame($inbox, $owner->notifications()->orderBy('id')->get()->map->getAttributes()->all());
        foreach ($reminders as $reminder) {
            $this->assertSame(ReminderStatus::Sent, $reminder->fresh()->status);
        }
    }

    public static function targetModes(): array
    {
        return [0 => [null, false]];
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

    #[DataProvider('ineligibleTargets')]
    public function test_structurally_valid_but_ineligible_sources_cancel_with_history(string $scenario): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $field = str_starts_with($scenario, 'session') ? 'study_session_id' : (str_starts_with($scenario, 'child') ? 'subtask_id' : 'task_id');
        $id = match ($field) {
            'task_id' => $task->id,
            'subtask_id' => $child->id,
            default => $session->id,
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
        return [0 => ['task_deleted']];
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_candidate_fails_closed_with_exact_rollback_and_batch_continues(string $scenario): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $data = ['task_id' => $task->id, 'subtask_id' => $child->id];
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
        return ['multiple targets' => ['multiple']];
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
        return [0 => ['notification_veto']];
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
        $reminder->delete();
        $this->assertNull($notification->fresh()->reminder_id);
        $this->assertSame($payload, $notification->fresh()->data);
        foreach (range(1, 2) as $unused) {
            $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'future_type', 'data' => [], 'reminder_id' => null]);
        }
        $this->assertDatabaseCount('notifications', 3);
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
