<?php

namespace Tests\Feature;

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

class SubtaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:20:30.987654Z'));
    }

    private function url(Task $task, Subtask $subtask, string $operation): string
    {
        return '/api/tasks/'.$task->id.'/subtasks/'.$subtask->id.'/'.$operation;
    }

    public static function operations(): array
    {
        return [['complete'], ['reopen']];
    }

    private function childForOperation(string $operation): Subtask
    {
        return Subtask::factory()->create([
            'status' => $operation === 'reopen' ? TaskStatus::Completed : TaskStatus::Pending,
            'completed_at' => $operation === 'reopen' ? '2026-10-10 12:00:00' : null,
        ])->refresh();
    }

    public function test_complete_is_late_utc_whole_second_and_preserves_exact_public_fields(): void
    {
        $subtask = Subtask::factory()->create(['title' => 'Report', 'description' => 'Keep', 'position' => 9,
            'estimated_minutes' => 50, 'deadline_at' => '2026-10-10 12:00:00']);
        $task = $subtask->task;
        $user = $task->user;
        $user->update(['timezone' => 'America/Los_Angeles']);
        $before = $subtask->refresh()->getAttributes();
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $this->travelTo(CarbonImmutable::parse('2026-10-12T13:20:30.987654+03:00'));
            $this->actingAs($user, 'web')->postJson($this->url($task, $subtask, 'complete'), [
                'task_id' => 999999, 'user_id' => 999999, 'title' => 'Ignored', 'description' => null,
                'position' => 1, 'estimated_minutes' => null, 'deadline_at' => null, 'status' => 'pending',
                'completed_at' => '2000-01-01T00:00:00Z', 'deleted_at' => '2000-01-01',
            ])->assertOk()->assertExactJson(['data' => [
                'id' => $subtask->id, 'task_id' => $task->id, 'title' => 'Report', 'description' => 'Keep', 'position' => 9,
                'status' => 'completed', 'estimated_minutes' => 50, 'deadline_at' => '2026-10-10T12:00:00Z',
                'completed_at' => '2026-10-12T10:20:30Z',
            ]]);
            $this->assertSame('2026-10-12 10:20:30', $subtask->refresh()->getRawOriginal('completed_at'));
            $this->assertSame(Arr::except($before, ['status', 'completed_at', 'updated_at']),
                Arr::except($subtask->getAttributes(), ['status', 'completed_at', 'updated_at']));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_complete_reopen_and_complete_again_records_new_instant_and_regular_patch_preserves_lifecycle(): void
    {
        $subtask = Subtask::factory()->create();
        $task = $subtask->task;
        $this->actingAs($task->user, 'web')->postJson($this->url($task, $subtask, 'complete'))->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-12T10:20:30Z');
        $this->patchJson('/api/tasks/'.$task->id.'/subtasks/'.$subtask->id, ['title' => 'Edited', 'status' => 'pending', 'completed_at' => null])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-12T10:20:30Z');
        $this->postJson($this->url($task, $subtask, 'reopen'), ['status' => 'completed', 'completed_at' => '2000-01-01T00:00:00Z',
            'task_id' => 999999, 'position' => 65535, 'title' => 'Ignored'])->assertOk()->assertExactJson(['data' => [
                'id' => $subtask->id, 'task_id' => $task->id, 'title' => 'Edited', 'description' => null, 'position' => 1,
                'status' => 'pending', 'estimated_minutes' => null, 'deadline_at' => null, 'completed_at' => null,
            ]]);
        $this->travelTo(CarbonImmutable::parse('2026-10-13T11:22:33.654321Z'));
        $this->postJson($this->url($task, $subtask, 'complete'))->assertOk()->assertJsonPath('data.completed_at', '2026-10-13T11:22:33Z');
        $this->assertSame('2026-10-13 11:22:33', $subtask->refresh()->getRawOriginal('completed_at'));
    }

    #[DataProvider('operations')]
    public function test_repeated_operation_preserves_all_persisted_data_without_update(string $operation): void
    {
        $child = $this->childForOperation($operation);
        $task = $child->task;
        $this->actingAs($task->user, 'web')->postJson($this->url($task, $child, $operation))->assertOk();
        $before = $child->refresh()->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T15:00:00Z'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson($this->url($task, $child, $operation))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($before, $child->refresh()->getAttributes());
        $this->assertSame([], array_values(array_filter($queries, fn ($query) => str_starts_with($query['query'], 'update '))));
    }

    #[DataProvider('parentStates')]
    public function test_parent_other_children_sessions_reminders_and_deadlines_are_unchanged(bool $completedParent): void
    {
        $task = Task::factory()->create(['status' => $completedParent ? TaskStatus::Completed : TaskStatus::Pending,
            'completed_at' => $completedParent ? '2026-10-10 12:00:00' : null, 'deadline_at' => '2026-10-11 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['deadline_at' => '2026-10-10 12:00:00']);
        $other = Subtask::factory()->for($task)->create(['position' => 2, 'status' => TaskStatus::Completed, 'completed_at' => '2026-10-09 12:00:00']);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id]);
        $reminder = Reminder::factory()->for($task->user)->create(['subtask_id' => $child->id]);
        $records = [$task, $other, $session, $reminder];
        $snapshots = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web');
        foreach (['complete', 'reopen', 'complete'] as $operation) {
            $this->postJson($this->url($task, $child, $operation))->assertOk();
            foreach ($records as $i => $record) {
                $this->assertSame($snapshots[$i], $record->refresh()->getAttributes());
            }
            $this->assertSame('2026-10-10 12:00:00', $child->refresh()->getRawOriginal('deadline_at'));
        }
        $this->assertDatabaseCount('subtasks', 2);
        $this->assertDatabaseCount('study_sessions', 1);
        $this->assertDatabaseCount('reminders', 1);
    }

    public static function parentStates(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('operations')]
    public function test_authentication_foreign_missing_deleted_and_mismatched_resources_are_rejected(string $operation): void
    {
        $child = $this->childForOperation($operation);
        $task = $child->task;
        $before = $child->getAttributes();
        $this->postJson($this->url($task, $child, $operation))->assertUnauthorized();
        $owned = Task::factory()->create();
        $this->actingAs($owned->user, 'web')->postJson($this->url($task, $child, $operation), ['status' => [], 'completed_at' => 'invalid'])
            ->assertNotFound();
        $this->postJson($this->url($owned, $child, $operation))->assertNotFound();
        $sameOwner = Subtask::factory()->for($owned)->create()->refresh();
        $sameBefore = $sameOwner->getAttributes();
        $another = Task::factory()->for($owned->user)->create();
        $this->postJson($this->url($another, $sameOwner, $operation))->assertNotFound();
        $this->postJson('/api/tasks/'.$owned->id.'/subtasks/999999/'.$operation)->assertNotFound();
        $this->postJson('/api/tasks/999999/subtasks/'.$sameOwner->id.'/'.$operation)->assertNotFound();
        $this->assertSame($before, $child->refresh()->getAttributes());
        $this->assertSame($sameBefore, $sameOwner->refresh()->getAttributes());
        $sameOwner->delete();
        $this->postJson($this->url($owned, $sameOwner, $operation))->assertNotFound();
        $live = Subtask::factory()->for($owned)->create();
        $owned->delete();
        $this->postJson($this->url($owned, $live, $operation))->assertNotFound();
        $this->assertNotSoftDeleted($live);
    }

    #[DataProvider('directContexts')]
    public function test_direct_service_calls_reject_forged_or_invalid_context(string $operation, string $scenario): void
    {
        $child = $this->childForOperation($operation);
        $task = $child->task;
        $user = $task->user;
        if ($scenario === 'foreign') {
            $user = User::factory()->create();
            $task->user_id = $user->id;
        } elseif ($scenario === 'mismatch') {
            $task = Task::factory()->for($user)->create();
            $child->task_id = $task->id;
        } elseif ($scenario === 'deleted parent') {
            $task->delete();
        } else {
            $child->delete();
        }
        $before = Subtask::withTrashed()->findOrFail($child->id)->getAttributes();
        try {
            app(SubtaskService::class)->$operation($user, $task, $child);
            $this->fail('The current persisted parent/child context must be enforced.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $child->refresh()->getAttributes());
        }
    }

    public static function directContexts(): array
    {
        $cases = [];
        foreach (['complete', 'reopen'] as $operation) {
            foreach (['foreign', 'mismatch', 'deleted parent', 'deleted child'] as $scenario) {
                $cases[] = [$operation, $scenario];
            }
        }

        return $cases;
    }

    #[DataProvider('staleStates')]
    public function test_stale_child_models_do_not_determine_the_transition(string $operation, bool $staleCompleted, bool $currentCompleted): void
    {
        $child = Subtask::factory()->create(['status' => $staleCompleted ? TaskStatus::Completed : TaskStatus::Pending,
            'completed_at' => $staleCompleted ? '2026-10-09 12:00:00' : null]);
        $task = $child->task;
        Subtask::findOrFail($child->id)->update(['title' => 'Latest', 'status' => $currentCompleted ? TaskStatus::Completed : TaskStatus::Pending,
            'completed_at' => $currentCompleted ? '2026-10-11 12:00:00' : null]);
        $result = app(SubtaskService::class)->$operation($task->user, $task, $child);
        $this->assertSame('Latest', $result->title);
        $this->assertSame($operation === 'complete' ? TaskStatus::Completed : TaskStatus::Pending, $result->status);
        $this->assertSame($operation === 'reopen' ? null : ($currentCompleted ? '2026-10-11 12:00:00' : '2026-10-12 10:20:30'),
            $result->getRawOriginal('completed_at'));
    }

    public static function staleStates(): array
    {
        return [['complete', false, true], ['complete', true, false], ['reopen', false, true], ['reopen', true, false]];
    }

    public function test_serialized_stale_requests_preserve_first_completion_then_allow_a_new_one(): void
    {
        $first = Subtask::factory()->create();
        $second = Subtask::findOrFail($first->id);
        $task = $first->task;
        $user = $task->user;
        $service = app(SubtaskService::class);
        $service->complete($user, $task, $first);
        $this->travelTo(CarbonImmutable::parse('2026-10-13T11:22:33Z'));
        $this->assertSame('2026-10-12 10:20:30', $service->complete($user, $task, $second)->getRawOriginal('completed_at'));
        $service->reopen($user, $task, $first);
        $this->assertSame('2026-10-13 11:22:33', $service->complete($user, $task, $second)->getRawOriginal('completed_at'));
    }

    #[DataProvider('operations')]
    public function test_mutation_uses_owner_parent_child_locks_and_one_atomic_status_timestamp_update(string $operation): void
    {
        $child = $this->childForOperation($operation);
        $task = $child->task;
        $user = $task->user;
        $level = DB::transactionLevel();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        });
        app(SubtaskService::class)->$operation($user, $task, $child);
        foreach (['users', 'tasks', 'subtasks'] as $i => $table) {
            $this->assertStringContainsString('from `'.$table.'`', $queries[$i]['sql']);
            $this->assertStringContainsString('for update', $queries[$i]['sql']);
        }
        $this->assertStringContainsString('`user_id` =', $queries[1]['sql']);
        $this->assertStringContainsString('`task_id` =', $queries[2]['sql']);
        $writes = array_values(array_filter($queries, fn ($query) => str_starts_with($query['sql'], 'update ')));
        $this->assertCount(1, $writes);
        $this->assertStringStartsWith('update `subtasks`', $writes[0]['sql']);
        $this->assertStringContainsString('`status` =', $writes[0]['sql']);
        $this->assertStringContainsString('`completed_at` =', $writes[0]['sql']);
        foreach ($queries as $query) {
            $this->assertSame($level + 1, $query['level']);
        }
        $this->assertSame($level, DB::transactionLevel());
    }

    public function test_lifecycle_does_not_repair_a_legacy_invalid_deadline(): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-05 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['deadline_at' => '2026-10-06 12:00:00']);
        $this->actingAs($task->user, 'web');
        foreach (['complete', 'reopen'] as $operation) {
            $this->postJson($this->url($task, $child, $operation))->assertOk()->assertJsonPath('data.deadline_at', '2026-10-06T12:00:00Z');
        }
        $this->patchJson('/api/tasks/'.$task->id.'/subtasks/'.$child->id, ['title' => 'Unrelated'])
            ->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
    }

    public function test_mutation_exception_rolls_back_lifecycle_pair(): void
    {
        $child = Subtask::factory()->create()->refresh();
        $task = $child->task;
        $user = $task->user;
        $before = $child->getAttributes();
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_starts_with($query->sql, 'update `subtasks`')) {
                $fail = false;
                throw new RuntimeException('Deliberate lifecycle failure');
            }
        });
        try {
            app(SubtaskService::class)->complete($user, $task, $child);
            $this->fail('The lifecycle write must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate lifecycle failure', $exception->getMessage());
        }
        $this->assertSame($before, $child->refresh()->getAttributes());
    }
}
