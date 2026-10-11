<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TaskDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:20:30Z'));
    }

    public function test_parent_and_live_descendants_soft_delete_preserving_history_and_all_active_routes_hide_them(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00', 'description' => 'Keep', 'priority' => 3])->refresh();
        $pending = Subtask::factory()->for($task)->create(['position' => 3]);
        $completed = Subtask::factory()->for($task)->create(['position' => 8, 'status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00']);
        $old = Subtask::factory()->for($task)->create(['position' => 10]);
        $this->travelTo(CarbonImmutable::parse('2026-10-10T12:00:00Z'));
        $old->delete();
        $oldBefore = $old->refresh()->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:20:30Z'));
        $records = [$task, $pending, $completed];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $other = Task::factory()->for($task->user)->create();
        $this->actingAs($task->user, 'web')->deleteJson('/api/tasks/'.$task->id, ['user_id' => 999999, 'task_id' => $other->id])->assertNoContent();
        foreach ($records as $i => $record) {
            $this->assertSoftDeleted($record);
            $this->assertSame(Arr::except($before[$i], ['deleted_at', 'updated_at']), Arr::except($record->refresh()->getAttributes(), ['deleted_at', 'updated_at']));
        }
        $this->assertSame($oldBefore, $old->refresh()->getAttributes());
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseCount('subtasks', 3);
        foreach (['', '/progress'] as $suffix) {
            $this->getJson('/api/tasks/'.$task->id.$suffix)->assertNotFound();
        }
        foreach (['complete', 'reopen'] as $operation) {
            $this->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertNotFound();
            $this->postJson('/api/tasks/'.$task->id.'/subtasks/'.$pending->id.'/'.$operation)->assertNotFound();
        }
        $this->patchJson('/api/tasks/'.$task->id, ['title' => 'Hidden'])->assertNotFound();
        $base = '/api/tasks/'.$task->id.'/subtasks';
        $this->getJson($base)->assertNotFound();
        $this->postJson($base, ['title' => 'Hidden'])->assertNotFound();
        $this->getJson($base.'/'.$pending->id)->assertNotFound();
        $this->patchJson($base.'/'.$pending->id, [])->assertNotFound();
        $this->deleteJson($base.'/'.$pending->id)->assertNotFound();
        $this->postJson($base.'/reorder', ['subtask_ids' => []])->assertNotFound();
        $this->getJson('/api/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);
        $this->getJson('/api/tasks/'.$other->id)->assertOk();
        $deletedBefore = $task->refresh()->getAttributes();
        $this->deleteJson('/api/tasks/'.$task->id)->assertNotFound();
        $this->assertSame($deletedBefore, $task->refresh()->getAttributes());
    }

    #[DataProvider('sessionCases')]
    public function test_task_session_cleanup_preserves_lifecycle_metadata_and_relations(string $status, string $start, bool $withChild, bool $cancel): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $predecessor = StudySession::factory()->for($task)->create(['status' => StudySessionStatus::Rescheduled]);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $withChild ? $child->id : null,
            'rescheduled_from_session_id' => $predecessor->id, 'status' => $status, 'starts_at' => $start, 'ends_at' => '2026-10-12 12:00:00',
            'completed_at' => $status === 'completed' ? '2026-10-12 11:00:00' : null,
            'actual_minutes' => $status === 'completed' ? 45 : null])->refresh();
        $before = $session->getAttributes();
        $previousBefore = $predecessor->refresh()->getAttributes();
        $this->actingAs($task->user, 'web')->deleteJson('/api/tasks/'.$task->id)->assertNoContent();
        if ($cancel) {
            $this->assertSame(StudySessionStatus::Cancelled, $session->refresh()->status);
            $this->assertSame(Arr::except($before, ['status', 'updated_at']), Arr::except($session->getAttributes(), ['status', 'updated_at']));
        } else {
            $this->assertSame($before, $session->refresh()->getAttributes());
        }
        $this->assertSame($previousBefore, $predecessor->refresh()->getAttributes());
        $this->assertDatabaseCount('study_sessions', 2);
    }

    public static function sessionCases(): array
    {
        return [['planned', '2026-10-12 10:20:29', false, false], ['planned', '2026-10-12 10:20:30', false, true],
            ['planned', '2026-10-12 10:20:31', true, true], ['completed', '2026-10-12 10:20:31', true, false],
            ['missed', '2026-10-12 10:20:31', true, false], ['rescheduled', '2026-10-12 10:20:31', false, false],
            ['cancelled', '2026-10-12 10:20:31', false, false]];
    }

    public function test_reminders_for_task_all_descendants_and_newly_cancelled_sessions_cancel_only_scheduled_records(): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $old = Subtask::factory()->for($task)->create();
        $old->delete();
        $future = StudySession::factory()->for($task)->create(['subtask_id' => $old->id, 'starts_at' => '2026-10-12 10:20:30', 'ends_at' => '2026-10-12 12:00:00']);
        $past = StudySession::factory()->for($task)->create();
        $cancel = [Reminder::factory()->for($task->user)->create(['task_id' => $task->id]),
            Reminder::factory()->for($task->user)->create(['subtask_id' => $child->id]),
            Reminder::factory()->for($task->user)->create(['subtask_id' => $old->id]),
            Reminder::factory()->for($task->user)->create(['study_session_id' => $future->id])];
        $sent = Reminder::factory()->for($task->user)->create(['task_id' => $task->id, 'status' => ReminderStatus::Sent, 'sent_at' => '2026-10-10 12:00:00']);
        $sentSession = Reminder::factory()->for($task->user)->create(['study_session_id' => $future->id, 'status' => ReminderStatus::Sent, 'sent_at' => '2026-10-10 12:00:00']);
        $cancelled = Reminder::factory()->for($task->user)->create(['subtask_id' => $child->id, 'status' => ReminderStatus::Cancelled]);
        $general = Reminder::factory()->for($task->user)->create();
        $unaffectedSession = Reminder::factory()->for($task->user)->create(['study_session_id' => $past->id]);
        $otherTask = Task::factory()->for($task->user)->create();
        $otherSession = StudySession::factory()->for($otherTask)->create(['starts_at' => '2026-10-12 10:20:31', 'ends_at' => '2026-10-12 12:00:00']);
        $otherReminder = Reminder::factory()->for($task->user)->create(['task_id' => $otherTask->id]);
        $preserve = [$old, $past, $sent, $sentSession, $cancelled, $general, $unaffectedSession, $otherTask, $otherSession, $otherReminder];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $preserve);
        $cancelBefore = array_map(fn ($record) => $record->refresh()->getAttributes(), $cancel);
        $this->actingAs($task->user, 'web')->deleteJson('/api/tasks/'.$task->id)->assertNoContent();
        $this->assertSame(StudySessionStatus::Cancelled, $future->refresh()->status);
        foreach ($cancel as $i => $reminder) {
            $this->assertSame(ReminderStatus::Cancelled, $reminder->refresh()->status);
            $this->assertSame(Arr::except($cancelBefore[$i], ['status', 'updated_at']), Arr::except($reminder->getAttributes(), ['status', 'updated_at']));
        }
        foreach ($preserve as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
        $this->assertDatabaseCount('reminders', 10);
    }

    public function test_delete_reloads_new_descendants_and_latest_parent_fields_instead_of_cached_models(): void
    {
        $task = Task::factory()->create();
        $user = $task->user;
        $task->load('subtasks');
        Task::findOrFail($task->id)->update(['description' => 'Latest persisted description']);
        $child = app(SubtaskService::class)->create($user, $task, ['title' => 'Added after stale snapshot']);
        app(TaskService::class)->delete($user, $task);
        $this->assertSoftDeleted($task);
        $this->assertSoftDeleted($child);
        $this->assertSame('Latest persisted description', $task->refresh()->description);
    }

    #[DataProvider('unsafeCases')]
    public function test_unsafe_active_dependencies_fail_without_cross_owner_or_partial_mutation(string $scenario): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $otherTask = Task::factory()->create();
        $otherChild = Subtask::factory()->for($otherTask)->create();
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id,
            'starts_at' => '2026-10-12 10:20:30', 'ends_at' => '2026-10-12 12:00:00']);
        $reminder = Reminder::factory()->for($task->user)->create(['task_id' => $task->id]);
        match ($scenario) {
            'session owner' => $session->update(['user_id' => $otherTask->user_id]),
            'session child' => $session->update(['subtask_id' => $otherChild->id]),
            'child linked by another task session' => $session->update(['task_id' => $otherTask->id]),
            'task reminder owner' => $reminder->update(['user_id' => $otherTask->user_id]),
            'deleted child reminder owner' => Reminder::factory()->for($otherTask->user)->create(['subtask_id' => $child->id]),
            'multiple targets' => $reminder->update(['subtask_id' => $otherChild->id]),
        };
        if ($scenario === 'deleted child reminder owner') {
            $child->delete();
        }
        $records = [$task, $child, $session, ...Reminder::query()->orderBy('id')->get()->all()];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web')->deleteJson('/api/tasks/'.$task->id)->assertUnprocessable()->assertJsonValidationErrors('deletion');
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }

    public static function unsafeCases(): array
    {
        return [['session owner'], ['session child'], ['child linked by another task session'], ['task reminder owner'],
            ['deleted child reminder owner'], ['multiple targets']];
    }

    public function test_security_and_direct_forged_or_stale_models_cannot_delete_foreign_or_deleted_tasks(): void
    {
        $task = Task::factory()->create()->refresh();
        $before = $task->getAttributes();
        $this->deleteJson('/api/tasks/'.$task->id)->assertUnauthorized();
        $other = User::factory()->create();
        $this->actingAs($other, 'web')->deleteJson('/api/tasks/'.$task->id, ['user_id' => $task->user_id])->assertNotFound();
        $this->deleteJson('/api/tasks/999999')->assertNotFound();
        $task->user_id = $other->id;
        try {
            app(TaskService::class)->delete($other, $task);
            $this->fail('Forged ownership must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $task->refresh()->getAttributes());
        }
        $user = $task->user;
        $task->delete();
        $deletedBefore = $task->refresh()->getAttributes();
        try {
            app(TaskService::class)->delete($user, $task);
            $this->fail('A deleted task must be excluded from reloading.');
        } catch (ModelNotFoundException) {
            $this->assertSame($deletedBefore, $task->refresh()->getAttributes());
        }
    }

    #[DataProvider('failureStages')]
    public function test_any_later_write_failure_rolls_back_cleanup_children_and_parent_with_stable_locks(string $table): void
    {
        $task = Task::factory()->create();
        $user = $task->user;
        $first = Subtask::factory()->for($task)->create();
        $second = Subtask::factory()->for($task)->create();
        $session = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-12 10:20:30', 'ends_at' => '2026-10-12 12:00:00']);
        $reminder = Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $records = [$task, $first, $second, $session, $reminder];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $queries = [];
        $fail = true;
        $level = DB::transactionLevel();
        DB::listen(function ($query) use (&$queries, &$fail, $table): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
            if ($fail && str_starts_with($query->sql, 'update `'.$table.'`')) {
                $fail = false;
                throw new RuntimeException('Deliberate later deletion failure');
            }
        });
        try {
            app(TaskService::class)->delete($user, $task);
            $this->fail('The selected mutation must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate later deletion failure', $exception->getMessage());
        }
        foreach (['users', 'tasks', 'subtasks', 'study_sessions', 'reminders'] as $i => $target) {
            $this->assertStringContainsString('from `'.$target.'`', $queries[$i]['sql']);
            $this->assertStringContainsString('for update', $queries[$i]['sql']);
            $this->assertSame($level + 1, $queries[$i]['level']);
        }
        foreach ([2, 3, 4] as $i) {
            $this->assertStringContainsString('order by `id` asc', $queries[$i]['sql']);
        }
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
        $this->assertSame($level, DB::transactionLevel());
    }

    public static function failureStages(): array
    {
        return [['study_sessions'], ['reminders'], ['subtasks'], ['tasks']];
    }
}
