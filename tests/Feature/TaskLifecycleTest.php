<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:20:30.987654Z'));
    }

    public static function operations(): array
    {
        return [['complete'], ['reopen']];
    }

    private function taskForOperation(string $operation): Task
    {
        return Task::factory()->create([
            'status' => $operation === 'reopen' ? TaskStatus::Completed : TaskStatus::Pending,
            'completed_at' => $operation === 'reopen' ? '2026-10-10 09:00:00' : null,
        ])->refresh();
    }

    public function test_complete_preserves_unrelated_fields_and_returns_exact_resource_with_actual_late_completion(): void
    {
        $subject = Subject::factory()->create();
        $task = Task::factory()->for($subject->user)->for($subject)->create([
            'title' => 'Курсова робота', 'description' => 'Prepare report', 'priority' => 3,
            'estimated_minutes' => 60, 'deadline_at' => '2026-10-10 12:00:00',
        ])->refresh();
        $before = $task->getAttributes();
        $other = User::factory()->create();
        $this->actingAs($subject->user, 'web')->postJson('/api/tasks/'.$task->id.'/complete', [
            'user_id' => $other->id, 'status' => 'pending', 'completed_at' => '2000-01-01T00:00:00Z',
            'title' => 'Ignored', 'subject_id' => null, 'description' => null, 'priority' => 1,
            'estimated_minutes' => 1, 'deadline_at' => null, 'deleted_at' => '2000-01-01',
        ])->assertOk()->assertExactJson(['data' => [
            'id' => $task->id, 'subject_id' => $subject->id, 'title' => 'Курсова робота', 'description' => 'Prepare report',
            'status' => 'completed', 'priority' => 3, 'estimated_minutes' => 60,
            'deadline_at' => '2026-10-10T12:00:00Z', 'completed_at' => '2026-10-12T10:20:30Z',
        ]]);
        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame('2026-10-12 10:20:30', $task->getRawOriginal('completed_at'));
        $this->assertSame(Arr::except($before, ['status', 'completed_at', 'updated_at']),
            Arr::except($task->getAttributes(), ['status', 'completed_at', 'updated_at']));
    }

    public function test_task_without_deadline_can_complete(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->postJson('/api/tasks/'.$task->id.'/complete')->assertOk()
            ->assertJsonPath('data.deadline_at', null)->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', '2026-10-12T10:20:30Z');
    }

    public function test_reopen_preserves_unrelated_fields_and_recompletion_uses_a_new_instant(): void
    {
        $subject = Subject::factory()->create();
        $task = Task::factory()->for($subject->user)->for($subject)->create([
            'title' => 'Original', 'description' => 'Keep', 'priority' => 1, 'estimated_minutes' => 50,
            'deadline_at' => '2026-10-10 12:00:00', 'status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 09:00:00',
        ])->refresh();
        $before = $task->getAttributes();
        $this->actingAs($subject->user, 'web')->postJson('/api/tasks/'.$task->id.'/reopen', [
            'status' => 'completed', 'completed_at' => '2000-01-01T00:00:00Z', 'user_id' => 999999,
            'title' => 'Ignored', 'subject_id' => null, 'description' => null, 'priority' => 3, 'estimated_minutes' => null, 'deadline_at' => null,
        ])->assertOk()->assertExactJson(['data' => [
            'id' => $task->id, 'subject_id' => $subject->id, 'title' => 'Original', 'description' => 'Keep',
            'status' => 'pending', 'priority' => 1, 'estimated_minutes' => 50,
            'deadline_at' => '2026-10-10T12:00:00Z', 'completed_at' => null,
        ]]);
        $task->refresh();
        $this->assertSame(Arr::except($before, ['status', 'completed_at', 'updated_at']),
            Arr::except($task->getAttributes(), ['status', 'completed_at', 'updated_at']));
        $this->travelTo(CarbonImmutable::parse('2026-10-13T11:22:33.654321Z'));
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk()->assertJsonPath('data.completed_at', '2026-10-13T11:22:33Z');
        $this->assertSame('2026-10-13 11:22:33', $task->refresh()->getRawOriginal('completed_at'));
    }

    #[DataProvider('operations')]
    public function test_repeated_operation_is_idempotent_without_any_update_query(string $operation): void
    {
        $task = $this->taskForOperation($operation);
        $this->actingAs($task->user, 'web')->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertOk();
        $before = $task->refresh()->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T15:00:00Z'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame([], array_values(array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'update '))));
    }

    public function test_completion_clock_is_utc_even_with_different_server_and_user_timezones(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Los_Angeles']);
        $task = Task::factory()->for($user)->create();
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $this->travelTo(CarbonImmutable::parse('2026-10-12T13:20:30.999999+03:00'));
            $this->actingAs($user, 'web')->postJson('/api/tasks/'.$task->id.'/complete')->assertOk()
                ->assertJsonPath('data.completed_at', '2026-10-12T10:20:30Z');
            $this->assertSame('2026-10-12 10:20:30', $task->refresh()->getRawOriginal('completed_at'));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[DataProvider('operations')]
    public function test_authentication_foreign_missing_and_deleted_tasks_are_rejected_without_mutation(string $operation): void
    {
        $task = $this->taskForOperation($operation);
        $before = $task->getAttributes();
        $this->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertUnauthorized();
        $other = Task::factory()->create()->refresh();
        $otherBefore = $other->getAttributes();
        $this->actingAs($other->user, 'web')->postJson('/api/tasks/'.$task->id.'/'.$operation, [
            'user_id' => $task->user_id, 'status' => [], 'completed_at' => 'invalid',
        ])->assertNotFound();
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame($otherBefore, $other->refresh()->getAttributes());
        $this->postJson('/api/tasks/999999/'.$operation)->assertNotFound();
        $other->delete();
        $deletedBefore = $other->refresh()->getAttributes();
        $this->postJson('/api/tasks/'.$other->id.'/'.$operation)->assertNotFound();
        $this->assertSame($deletedBefore, $other->refresh()->getAttributes());
    }

    #[DataProvider('operations')]
    public function test_direct_service_calls_cannot_bypass_owned_reload_with_a_forged_model(string $operation): void
    {
        $task = $this->taskForOperation($operation);
        $before = $task->getAttributes();
        $other = User::factory()->create();
        $task->user_id = $other->id;
        try {
            app(TaskService::class)->$operation($other, $task);
            $this->fail('A forged input model must not bypass the scoped reload.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $task->refresh()->getAttributes());
        }
    }

    #[DataProvider('operations')]
    public function test_direct_service_calls_do_not_mutate_soft_deleted_tasks(string $operation): void
    {
        $task = $this->taskForOperation($operation);
        $user = $task->user;
        $task->delete();
        $before = $task->refresh()->getAttributes();
        try {
            app(TaskService::class)->$operation($user, $task);
            $this->fail('A deleted task must be excluded from scoped reloading.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $task->refresh()->getAttributes());
        }
    }

    public function test_lifecycle_leaves_subtasks_sessions_reminders_and_unrelated_tasks_unchanged(): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-10 12:00:00']);
        $pending = Subtask::factory()->for($task)->create(['position' => 1, 'estimated_minutes' => 30, 'deadline_at' => '2026-10-09 12:00:00']);
        $completed = Subtask::factory()->for($task)->create(['position' => 2, 'status' => TaskStatus::Completed,
            'completed_at' => '2026-10-08 12:00:00', 'estimated_minutes' => 20, 'deadline_at' => '2026-10-09 12:00:00']);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $pending->id]);
        $reminder = Reminder::factory()->for($task->user)->for($task)->create();
        $unrelated = Task::factory()->for($task->user)->create();
        $records = [$pending, $completed, $session, $reminder, $unrelated];
        $snapshots = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web');
        foreach (['complete', 'reopen'] as $operation) {
            $this->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertOk();
            foreach ($records as $index => $record) {
                $this->assertSame($snapshots[$index], $record->refresh()->getAttributes());
            }
            $this->assertSame('2026-10-10 12:00:00', $task->refresh()->getRawOriginal('deadline_at'));
        }
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseCount('subtasks', 2);
        $this->assertDatabaseCount('study_sessions', 1);
        $this->assertDatabaseCount('reminders', 1);
    }

    public function test_completed_subtasks_do_not_automatically_complete_the_parent(): void
    {
        $subtask = Subtask::factory()->create(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 09:00:00']);
        $this->actingAs($subtask->task->user, 'web')->getJson('/api/tasks/'.$subtask->task_id)->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.completed_at', null);
    }

    public function test_lifecycle_preserves_malformed_subject_link_and_patch_still_requires_repair(): void
    {
        $foreign = Subject::factory()->create(['name' => 'Private subject']);
        $task = Task::factory()->create(['subject_id' => $foreign->id]);
        $subjectBefore = $foreign->refresh()->getAttributes();
        $this->actingAs($task->user, 'web');
        foreach (['complete', 'reopen'] as $operation) {
            $this->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertOk()->assertJsonPath('data.subject_id', null)
                ->assertJsonMissing(['name' => 'Private subject']);
            $this->assertSame($foreign->id, $task->refresh()->subject_id);
        }
        $before = $task->getAttributes();
        $this->patchJson('/api/tasks/'.$task->id, ['title' => 'Unrelated'])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame($subjectBefore, $foreign->refresh()->getAttributes());
    }

    public function test_regular_patch_preserves_both_completed_and_reopened_lifecycle_states(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/tasks', ['title' => 'Test', 'status' => 'completed'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.completed_at', null);
        $task = Task::sole();
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk();
        $this->patchJson('/api/tasks/'.$task->id, ['title' => 'Edited', 'status' => 'pending', 'completed_at' => null])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-12T10:20:30Z');
        $this->postJson('/api/tasks/'.$task->id.'/reopen')->assertOk();
        $this->patchJson('/api/tasks/'.$task->id, ['title' => 'Edited again', 'status' => 'completed', 'completed_at' => '2000-01-01T00:00:00Z'])
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.completed_at', null);
    }

    public static function staleStates(): array
    {
        return [
            'complete stale pending' => ['complete', false, true],
            'complete stale completed' => ['complete', true, false],
            'reopen stale pending' => ['reopen', false, true],
            'reopen stale completed' => ['reopen', true, false],
        ];
    }

    #[DataProvider('staleStates')]
    public function test_service_transition_uses_latest_persisted_status_not_stale_input(string $operation, bool $staleCompleted, bool $currentCompleted): void
    {
        $task = Task::factory()->create(['status' => $staleCompleted ? TaskStatus::Completed : TaskStatus::Pending,
            'completed_at' => $staleCompleted ? '2026-10-09 09:00:00' : null]);
        Task::findOrFail($task->id)->update(['status' => $currentCompleted ? TaskStatus::Completed : TaskStatus::Pending,
            'completed_at' => $currentCompleted ? '2026-10-11 09:00:00' : null, 'title' => 'Latest']);
        $result = app(TaskService::class)->$operation($task->user, $task);
        $this->assertSame('Latest', $result->title);
        $this->assertSame($operation === 'complete' ? TaskStatus::Completed : TaskStatus::Pending, $result->status);
        $this->assertSame($operation === 'reopen' ? null : ($currentCompleted ? '2026-10-11 09:00:00' : '2026-10-12 10:20:30'),
            $result->getRawOriginal('completed_at'));
    }

    public function test_serialized_requests_using_independently_loaded_models_keep_first_completion_and_support_reopening(): void
    {
        $first = Task::factory()->create();
        $second = Task::findOrFail($first->id);
        $user = $first->user;
        $service = app(TaskService::class);
        $service->complete($user, $first);
        $this->travelTo(CarbonImmutable::parse('2026-10-13T11:22:33Z'));
        $result = $service->complete($user, $second);
        $this->assertSame('2026-10-12 10:20:30', $result->getRawOriginal('completed_at'));
        $service->reopen($user, $first);
        $result = $service->complete($user, $second);
        $this->assertSame(TaskStatus::Completed, $result->status);
        $this->assertSame('2026-10-13 11:22:33', $result->getRawOriginal('completed_at'));
    }

    #[DataProvider('operations')]
    public function test_owner_and_task_locks_precede_single_atomic_status_timestamp_write(string $operation): void
    {
        $task = $this->taskForOperation($operation);
        $user = $task->user;
        $level = DB::transactionLevel();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        });
        app(TaskService::class)->$operation($user, $task);
        $this->assertStringContainsString('from `users`', $queries[0]['sql']);
        $this->assertStringContainsString('for update', $queries[0]['sql']);
        $this->assertStringContainsString('from `tasks`', $queries[1]['sql']);
        $this->assertStringContainsString('`user_id` =', $queries[1]['sql']);
        $this->assertStringContainsString('for update', $queries[1]['sql']);
        $writes = array_values(array_filter($queries, fn ($query) => str_starts_with($query['sql'], 'update ')));
        $this->assertCount(1, $writes);
        $this->assertStringStartsWith('update `tasks`', $writes[0]['sql']);
        $this->assertStringContainsString('`status` =', $writes[0]['sql']);
        $this->assertStringContainsString('`completed_at` =', $writes[0]['sql']);
        foreach ($queries as $query) {
            $this->assertSame($level + 1, $query['level']);
        }
        $this->assertSame($level, DB::transactionLevel());
    }
}
