<?php

namespace Tests\Feature;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;
use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\SubtaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SubtaskDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:20:30Z'));
    }

    private function url(Task $task, Subtask $child): string
    {
        return '/api/tasks/'.$task->id.'/subtasks/'.$child->id;
    }

    public function test_soft_delete_preserves_metadata_parent_sibling_positions_and_refreshes_active_apis(): void
    {
        $child = Subtask::factory()->create(['position' => 8, 'description' => 'Keep', 'estimated_minutes' => 30,
            'deadline_at' => '2026-10-10 12:00:00', 'status' => TaskStatus::Completed, 'completed_at' => '2026-10-09 12:00:00'])->refresh();
        $task = $child->task;
        $sibling = Subtask::factory()->for($task)->create(['position' => 3])->refresh();
        $before = $child->getAttributes();
        $parentBefore = $task->getAttributes();
        $siblingBefore = $sibling->getAttributes();
        $this->actingAs($task->user, 'web')->deleteJson($this->url($task, $child), ['user_id' => 999999, 'task_id' => 999999])->assertNoContent();
        $this->assertSoftDeleted($child);
        $this->assertSame(Arr::except($before, ['deleted_at', 'updated_at']), Arr::except($child->refresh()->getAttributes(), ['deleted_at', 'updated_at']));
        $this->assertSame($parentBefore, $task->refresh()->getAttributes());
        $this->assertSame($siblingBefore, $sibling->refresh()->getAttributes());
        $this->assertDatabaseCount('subtasks', 2);
        $this->getJson($this->url($task, $child))->assertNotFound();
        $this->getJson('/api/tasks/'.$task->id.'/subtasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sibling->id);
        $this->getJson('/api/tasks/'.$task->id.'/progress')->assertOk()->assertJsonPath('data.total_subtasks', 1)->assertJsonPath('data.progress_percent', 0);
        $this->postJson('/api/tasks/'.$task->id.'/subtasks/reorder', ['subtask_ids' => [$child->id, $sibling->id]])->assertUnprocessable();
        $this->postJson('/api/tasks/'.$task->id.'/subtasks', ['title' => 'Appended'])->assertCreated()->assertJsonPath('data.position', 4);
        $deletedBefore = $child->refresh()->getAttributes();
        $this->deleteJson($this->url($task, $child))->assertNotFound();
        $this->assertSame($deletedBefore, $child->refresh()->getAttributes());
    }

    #[DataProvider('sessionCases')]
    public function test_only_future_planned_sessions_and_their_scheduled_reminders_are_cancelled(string $status, string $start, bool $cancel): void
    {
        $child = Subtask::factory()->create();
        $task = $child->task;
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'status' => $status,
            'starts_at' => $start, 'ends_at' => '2026-10-12 12:00:00',
            'completed_at' => $status === 'completed' ? '2026-10-12 11:00:00' : null,
            'actual_minutes' => $status === 'completed' ? 40 : null])->refresh();
        $reminder = Reminder::factory()->for($task->user)->create(['study_session_id' => $session->id])->refresh();
        $before = $session->getAttributes();
        $reminderBefore = $reminder->getAttributes();
        $this->actingAs($task->user, 'web')->deleteJson($this->url($task, $child))->assertNoContent();
        if ($cancel) {
            $this->assertSame(StudySessionStatus::Cancelled, $session->refresh()->status);
            $this->assertSame(ReminderStatus::Cancelled, $reminder->refresh()->status);
            $this->assertSame(Arr::except($before, ['status', 'updated_at']), Arr::except($session->getAttributes(), ['status', 'updated_at']));
            $this->assertSame(Arr::except($reminderBefore, ['status', 'updated_at']), Arr::except($reminder->getAttributes(), ['status', 'updated_at']));
        } else {
            $this->assertSame($before, $session->refresh()->getAttributes());
            $this->assertSame($reminderBefore, $reminder->refresh()->getAttributes());
        }
        $this->assertDatabaseCount('study_sessions', 1);
        $this->assertDatabaseCount('reminders', 1);
    }

    public static function sessionCases(): array
    {
        return [['planned', '2026-10-12 10:20:29', false], ['planned', '2026-10-12 10:20:30', true],
            ['planned', '2026-10-12 10:20:31', true], ['completed', '2026-10-12 10:20:31', false],
            ['missed', '2026-10-12 10:20:31', false], ['rescheduled', '2026-10-12 10:20:31', false], ['cancelled', '2026-10-12 10:20:31', false]];
    }

    public function test_fractional_utc_cutoff_does_not_round_a_past_start_up_to_now(): void
    {
        $child = Subtask::factory()->create();
        $task = $child->task;
        $task->user->update(['timezone' => 'America/Los_Angeles']);
        $past = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-12 10:20:30', 'ends_at' => '2026-10-12 12:00:00']);
        $future = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-12 10:20:31', 'ends_at' => '2026-10-12 12:00:00']);
        $zone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $this->travelTo(CarbonImmutable::parse('2026-10-12T13:20:30.500000+03:00'));
            $this->actingAs($task->user, 'web')->deleteJson($this->url($task, $child))->assertNoContent();
            $this->assertSame(StudySessionStatus::Planned, $past->refresh()->status);
            $this->assertSame(StudySessionStatus::Cancelled, $future->refresh()->status);
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_direct_reminders_cancel_without_changing_sent_cancelled_general_or_sibling_history(): void
    {
        $child = Subtask::factory()->create(['deadline_at' => '2026-10-15 12:00:00']);
        $task = $child->task;
        $sibling = Subtask::factory()->for($task)->create();
        $scheduled = Reminder::factory()->for($task->user)->create(['subtask_id' => $child->id,
            'anchor' => ReminderAnchor::SubtaskDeadline, 'offset_minutes' => -30, 'message' => 'Keep original trigger'])->refresh();
        $sent = Reminder::factory()->for($task->user)->create(['subtask_id' => $child->id, 'status' => ReminderStatus::Sent, 'sent_at' => '2026-10-10 12:00:00']);
        $cancelled = Reminder::factory()->for($task->user)->create(['subtask_id' => $child->id, 'status' => ReminderStatus::Cancelled]);
        $general = Reminder::factory()->for($task->user)->create();
        $other = Reminder::factory()->for($task->user)->create(['subtask_id' => $sibling->id]);
        $directTask = Reminder::factory()->for($task->user)->create(['task_id' => $task->id]);
        $records = [$sent, $cancelled, $general, $other, $directTask];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $scheduledBefore = $scheduled->getAttributes();
        $this->actingAs($task->user, 'web')->deleteJson($this->url($task, $child))->assertNoContent();
        $this->assertSame(ReminderStatus::Cancelled, $scheduled->refresh()->status);
        $this->assertSame(Arr::except($scheduledBefore, ['status', 'updated_at']), Arr::except($scheduled->getAttributes(), ['status', 'updated_at']));
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }

    public function test_authentication_and_nested_visibility_and_direct_service_scoping(): void
    {
        $child = Subtask::factory()->create()->refresh();
        $task = $child->task;
        $before = $child->getAttributes();
        $this->deleteJson($this->url($task, $child))->assertUnauthorized();
        $foreign = User::factory()->create();
        $this->actingAs($foreign, 'web')->deleteJson($this->url($task, $child), ['task_id' => 999999])->assertNotFound();
        $owned = Task::factory()->for($foreign)->create();
        $this->deleteJson($this->url($owned, $child))->assertNotFound();
        $otherChild = Subtask::factory()->for($owned)->create();
        $anotherOwned = Task::factory()->for($foreign)->create();
        $this->deleteJson($this->url($anotherOwned, $otherChild))->assertNotFound();
        $this->deleteJson('/api/tasks/'.$owned->id.'/subtasks/999999')->assertNotFound();
        $owned->delete();
        $this->deleteJson($this->url($owned, $otherChild))->assertNotFound();
        $this->deleteJson('/api/tasks/999999/subtasks/'.$child->id)->assertNotFound();
        $task->user_id = $foreign->id;
        try {
            app(SubtaskService::class)->delete($foreign, $task, $child);
            $this->fail('Forged ownership must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $child->refresh()->getAttributes());
        }
        $task->refresh();
        $child->task_id = $anotherOwned->id;
        try {
            app(SubtaskService::class)->delete($foreign, $anotherOwned, $child);
            $this->fail('Forged child nesting must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $child->refresh()->getAttributes());
        }
    }

    #[DataProvider('unsafeCases')]
    public function test_unsafe_live_dependencies_reject_deletion_without_any_mutation(string $scenario): void
    {
        $child = Subtask::factory()->create();
        $task = $child->task;
        $other = Task::factory()->create();
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id,
            'starts_at' => '2026-10-12 10:20:30', 'ends_at' => '2026-10-12 12:00:00']);
        $reminder = Reminder::factory()->for($task->user)->create(['subtask_id' => $child->id]);
        match ($scenario) {
            'session owner' => $session->update(['user_id' => $other->user_id]),
            'session task' => $session->update(['task_id' => $other->id]),
            'reminder owner' => $reminder->update(['user_id' => $other->user_id]),
            'multiple targets' => $reminder->update(['task_id' => $other->id]),
            'wrong anchor' => $reminder->update(['anchor' => ReminderAnchor::TaskDeadline, 'offset_minutes' => -30]),
            'session reminder owner' => Reminder::factory()->for($other->user)->create(['study_session_id' => $session->id]),
        };
        $records = [$task, $child, $session, ...Reminder::query()->orderBy('id')->get()->all()];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web')->deleteJson($this->url($task, $child))->assertUnprocessable()->assertExactJson([
            'message' => 'This work cannot be deleted safely because of inconsistent dependencies.',
            'errors' => ['deletion' => ['This work cannot be deleted safely because of inconsistent dependencies.']],
        ]);
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }

    public static function unsafeCases(): array
    {
        return [['session owner'], ['session task'], ['reminder owner'], ['multiple targets'], ['wrong anchor'], ['session reminder owner']];
    }

    public function test_malformed_inactive_foreign_history_is_preserved_without_blocking_deletion(): void
    {
        $child = Subtask::factory()->create();
        $task = $child->task;
        $foreign = User::factory()->create();
        $past = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'user_id' => $foreign->id]);
        $sent = Reminder::factory()->for($foreign)->create(['subtask_id' => $child->id, 'status' => ReminderStatus::Sent, 'sent_at' => '2026-10-10 12:00:00']);
        $records = [$past, $sent];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web')->deleteJson($this->url($task, $child))->assertNoContent();
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }

    public function test_later_reminder_failure_rolls_back_sessions_and_child_and_uses_stable_lock_order(): void
    {
        $child = Subtask::factory()->create();
        $task = $child->task;
        $user = $task->user;
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-12 10:20:30', 'ends_at' => '2026-10-12 12:00:00']);
        $reminder = Reminder::factory()->for($user)->create(['study_session_id' => $session->id]);
        $records = [$child, $session, $reminder];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $queries = [];
        $level = DB::transactionLevel();
        $fail = true;
        DB::listen(function ($query) use (&$queries, &$fail): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
            if ($fail && str_starts_with($query->sql, 'update `reminders`')) {
                $fail = false;
                throw new RuntimeException('Deliberate cleanup failure');
            }
        });
        try {
            app(SubtaskService::class)->delete($user, $task, $child);
            $this->fail('The reminder write must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate cleanup failure', $exception->getMessage());
        }
        foreach (['users', 'tasks', 'subtasks', 'study_sessions', 'reminders'] as $i => $table) {
            $this->assertStringContainsString('from `'.$table.'`', $queries[$i]['sql']);
            $this->assertStringContainsString('for update', $queries[$i]['sql']);
            $this->assertSame($level + 1, $queries[$i]['level']);
        }
        foreach ([3, 4] as $i) {
            $this->assertStringContainsString('order by `id` asc', $queries[$i]['sql']);
        }
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
        $this->assertSame($level, DB::transactionLevel());
    }
}
