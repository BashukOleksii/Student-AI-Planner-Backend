<?php

namespace Tests\Feature;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

class ReminderPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, RefreshDatabase;

    public function test_exact_schema_general_absolute_factory_and_database_defaults(): void
    {
        $this->assertTableDefinition('reminders', [
            'id' => ['bigint unsigned', false, null],
            'user_id' => ['bigint unsigned', false, null],
            'task_id' => ['bigint unsigned', true, null],
            'subtask_id' => ['bigint unsigned', true, null],
            'study_session_id' => ['bigint unsigned', true, null],
            'message' => ['varchar(500)', true, null],
            'trigger_at' => ['datetime', false, null],
            'anchor' => ['varchar(32)', true, null],
            'offset_minutes' => ['smallint', true, null],
            'status' => ['varchar(20)', false, 'scheduled'],
            'sent_at' => ['datetime', true, null],
            'created_at' => ['timestamp', true, null],
            'updated_at' => ['timestamp', true, null],
        ]);
        $reminder = Reminder::factory()->create()->refresh();
        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        foreach (['task_id', 'subtask_id', 'study_session_id', 'message', 'anchor', 'offset_minutes', 'sent_at'] as $column) {
            $this->assertNull($reminder->$column);
        }
        foreach (['task', 'subtask', 'studySession'] as $relation) {
            $this->assertNull($reminder->$relation);
        }
        $this->assertInstanceOf(Carbon::class, $reminder->trigger_at);
        $this->assertSame('UTC', $reminder->trigger_at->timezoneName);
        $minimal = Reminder::create($reminder->only(['user_id', 'trigger_at']))->refresh();
        $this->assertSame(ReminderStatus::Scheduled, $minimal->status);
        $this->assertSame(['scheduled', 'sent', 'cancelled'], array_column(ReminderStatus::cases(), 'value'));
        $this->assertSame(['task_deadline', 'subtask_deadline', 'session_start'], array_column(ReminderAnchor::cases(), 'value'));
    }

    #[DataProvider('statuses')]
    public function test_statuses_and_sent_datetime_cast(string $status): void
    {
        $values = ['status' => $status, 'sent_at' => $status === 'sent' ? '2026-10-05 08:00:00' : null];
        $reminder = Reminder::factory()->create($values)->refresh();
        $this->assertSame(ReminderStatus::from($status), $reminder->status);
        DB::table('reminders')->where('id', $reminder->id)->update($values);
        $this->assertSame(ReminderStatus::from($status), $reminder->refresh()->status);
        if ($status === 'sent') {
            $this->assertInstanceOf(Carbon::class, $reminder->sent_at);
            $reminder->update(['status' => ReminderStatus::Cancelled, 'sent_at' => null]);
            $this->assertNull($reminder->refresh()->sent_at);
        } else {
            $this->assertNull($reminder->sent_at);
        }
    }

    public static function statuses(): array
    {
        return [['scheduled'], ['sent'], ['cancelled']];
    }

    #[DataProvider('anchors')]
    public function test_relative_anchors_signed_offsets_relationships_and_target_set_null(string $model, string $relation, string $column, string $anchor, int $offset): void
    {
        $target = $model::factory()->create();
        $user = $target instanceof Subtask ? $target->task->user : $target->user;
        $reminder = Reminder::factory()->for($user)->for($target, $relation)->create([
            'anchor' => $anchor, 'offset_minutes' => $offset, 'message' => 'Test reminder',
        ])->refresh();
        $this->assertSame(ReminderAnchor::from($anchor), $reminder->anchor);
        $this->assertSame($offset, $reminder->offset_minutes);
        $this->assertSame('Test reminder', $reminder->message);
        $this->assertTrue($reminder->$relation->is($target));
        $this->assertTrue($reminder->user->is($user));
        $this->assertSame([$reminder->id], $target->reminders->modelKeys());
        $this->assertSame([$reminder->id], $user->reminders->modelKeys());
        in_array($model, [Task::class, Subtask::class], true) ? $target->forceDelete() : $target->delete();
        $this->assertModelExists($reminder);
        $this->assertNull($reminder->refresh()->$column);
        $this->assertNull($reminder->$relation);
        // FK SET NULL leaves relative metadata; future purge reconciliation must clean it up.
        $this->assertSame(ReminderAnchor::from($anchor), $reminder->anchor);
        $this->assertSame($offset, $reminder->offset_minutes);
        $reminder->update(['anchor' => null, 'offset_minutes' => null]);
        $this->assertNull($reminder->refresh()->anchor);
    }

    public static function anchors(): array
    {
        return [
            [Task::class, 'task', 'task_id', 'task_deadline', -32768],
            [Subtask::class, 'subtask', 'subtask_id', 'subtask_deadline', 0],
            [StudySession::class, 'studySession', 'study_session_id', 'session_start', 32767],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_mysql_rejects_invalid_values_on_insert_and_update(array $changes, string|array $constraint): void
    {
        $this->assertInsertAndUpdateRejected(Reminder::factory()->create(), $changes, 3819, $constraint);
    }

    public static function invalidValues(): array
    {
        return [
            [['status' => 'unsupported'], ['reminders_valid_status', 'reminders_sent_consistency']],
            [['status' => ''], ['reminders_valid_status', 'reminders_sent_consistency']],
            [['anchor' => 'unsupported', 'offset_minutes' => -60], 'reminders_valid_anchor'],
            [['anchor' => '', 'offset_minutes' => 0], 'reminders_valid_anchor'],
            [['anchor' => 'task_deadline'], 'reminders_anchor_offset_pair'],
            [['offset_minutes' => 0], 'reminders_anchor_offset_pair'],
            [['status' => 'sent'], 'reminders_sent_consistency'],
            [['sent_at' => '2026-10-05 08:00:00'], 'reminders_sent_consistency'],
            [['status' => 'cancelled', 'sent_at' => '2026-10-05 08:00:00'], 'reminders_sent_consistency'],
        ];
    }

    #[DataProvider('missingTargets')]
    public function test_foreign_keys_reject_missing_targets(string $column, string $model): void
    {
        $missing = $model::factory()->create();
        in_array($model, [Task::class, Subtask::class], true) ? $missing->forceDelete() : $missing->delete();
        $this->assertInsertAndUpdateRejected(Reminder::factory()->create(), [$column => $missing->id], 1452, 'reminders_'.$column.'_foreign');
    }

    public static function missingTargets(): array
    {
        return [['user_id', User::class], ['task_id', Task::class], ['subtask_id', Subtask::class], ['study_session_id', StudySession::class]];
    }

    public function test_user_isolation_and_cascade(): void
    {
        $first = Reminder::factory()->create();
        $other = Reminder::factory()->create();
        $this->assertSame([$first->id], $first->user->reminders->modelKeys());
        $this->assertSame([$other->id], $other->user->reminders->modelKeys());
        $first->user->delete();
        $this->assertModelMissing($first);
        $this->assertModelExists($other);
    }

    public function test_multiple_targets_mismatch_and_cross_owner_links_are_invalid_domain_states_permitted_by_database(): void
    {
        $subtask = Subtask::factory()->create();
        $session = StudySession::factory()->for($subtask->task)->for($subtask)->create();
        $reminder = Reminder::factory()->for($session->user)->create();
        // Deliberately invalid domain data: max-one-target cannot be a SET NULL FK CHECK.
        $reminder->update(['task_id' => $session->task_id, 'subtask_id' => $subtask->id, 'study_session_id' => $session->id]);
        $this->assertSame($session->task_id, $reminder->refresh()->task_id);
        $this->assertSame($subtask->id, $reminder->subtask_id);
        $this->assertSame($session->id, $reminder->study_session_id);
        // Mismatched anchor/target and missing target must be rejected by future Reminder Service.
        $reminder->update(['subtask_id' => null, 'study_session_id' => null, 'anchor' => ReminderAnchor::SessionStart, 'offset_minutes' => -60]);
        $this->assertNull($reminder->refresh()->study_session_id);
        $this->assertSame(ReminderAnchor::SessionStart, $reminder->anchor);
        $reminder->update(['task_id' => null]);
        $this->assertModelExists($reminder);
        $otherSubtask = Subtask::factory()->create();
        $otherSession = StudySession::factory()->for($otherSubtask->task)->create();
        foreach (['task_id' => $otherSubtask->task_id, 'subtask_id' => $otherSubtask->id, 'study_session_id' => $otherSession->id] as $column => $id) {
            $reminder->update(['task_id' => null, 'subtask_id' => null, 'study_session_id' => null, $column => $id]);
            $this->assertSame($id, $reminder->refresh()->$column);
        }
        $this->assertNotSame($reminder->user_id, $otherSession->user_id);
    }

    public function test_keys_indexes_and_only_db_safe_checks(): void
    {
        $this->assertForeignKeys('reminders', [
            'user_id' => ['users', 'cascade'], 'task_id' => ['tasks', 'set null'],
            'subtask_id' => ['subtasks', 'set null'], 'study_session_id' => ['study_sessions', 'set null'],
        ]);
        $this->assertIndex('reminders', 'reminders_status_trigger_at_index', ['status', 'trigger_at']);
        $this->assertIndex('reminders', 'reminders_user_id_status_trigger_at_index', ['user_id', 'status', 'trigger_at']);
        $this->assertEnforcedChecks('reminders', ['reminders_valid_status', 'reminders_valid_anchor', 'reminders_anchor_offset_pair', 'reminders_sent_consistency']);
    }
}
