<?php

namespace Tests\Feature;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

#[Group('migration')]
#[Group('mysql')]
class CompactReminderPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, RefreshDatabase;

    public function test_exact_schema_general_absolute_factory_and_database_defaults(): void
    {
        $this->assertTableDefinition('reminders', ['id' => ['bigint unsigned', false, null], 'user_id' => ['bigint unsigned', false, null], 'task_id' => ['bigint unsigned', true, null], 'subtask_id' => ['bigint unsigned', true, null], 'study_session_id' => ['bigint unsigned', true, null], 'message' => ['varchar(500)', true, null], 'trigger_at' => ['datetime', false, null], 'anchor' => ['varchar(32)', true, null], 'offset_minutes' => ['smallint', true, null], 'status' => ['varchar(20)', false, 'scheduled'], 'sent_at' => ['datetime', true, null], 'created_at' => ['timestamp', true, null], 'updated_at' => ['timestamp', true, null]]);
        $reminder = Reminder::factory()->create()->refresh();
        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        foreach (['task_id', 'subtask_id', 'study_session_id', 'message', 'anchor', 'offset_minutes', 'sent_at'] as $column) {
            $this->assertNull($reminder->{$column});
        }
        foreach (['task', 'subtask', 'studySession'] as $relation) {
            $this->assertNull($reminder->{$relation});
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
        return [0 => ['scheduled']];
    }

    #[DataProvider('anchors')]
    public function test_relative_anchors_signed_offsets_relationships_and_target_set_null(string $model, string $relation, string $column, string $anchor, int $offset): void
    {
        $target = $model::factory()->create();
        $user = $target instanceof Subtask ? $target->task->user : $target->user;
        $reminder = Reminder::factory()->for($user)->for($target, $relation)->create(['anchor' => $anchor, 'offset_minutes' => $offset, 'message' => 'Test reminder'])->refresh();
        $this->assertSame(ReminderAnchor::from($anchor), $reminder->anchor);
        $this->assertSame($offset, $reminder->offset_minutes);
        $this->assertSame('Test reminder', $reminder->message);
        $this->assertTrue($reminder->{$relation}->is($target));
        $this->assertTrue($reminder->user->is($user));
        $this->assertSame([$reminder->id], $target->reminders->modelKeys());
        $this->assertSame([$reminder->id], $user->reminders->modelKeys());
        in_array($model, [Task::class, Subtask::class], true) ? $target->forceDelete() : $target->delete();
        $this->assertModelExists($reminder);
        $this->assertNull($reminder->refresh()->{$column});
        $this->assertNull($reminder->{$relation});
        $this->assertSame(ReminderAnchor::from($anchor), $reminder->anchor);
        $this->assertSame($offset, $reminder->offset_minutes);
        $reminder->update(['anchor' => null, 'offset_minutes' => null]);
        $this->assertNull($reminder->refresh()->anchor);
    }

    public static function anchors(): array
    {
        return [0 => ['App\Models\Task', 'task', 'task_id', 'task_deadline', -32768]];
    }
}
