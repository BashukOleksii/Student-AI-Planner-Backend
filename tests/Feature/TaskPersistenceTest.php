<?php

namespace Tests\Feature;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Subject;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

class TaskPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, RefreshDatabase;

    #[DataProvider('taskModels')]
    public function test_exact_schema_defaults_casts_and_soft_deletion(string $model): void
    {
        $isTask = $model === Task::class;
        $table = $isTask ? 'tasks' : 'subtasks';
        $this->assertTableDefinition($table, [
            'id' => ['bigint unsigned', false, null],
            ...($isTask ? ['user_id' => ['bigint unsigned', false, null], 'subject_id' => ['bigint unsigned', true, null]] : ['task_id' => ['bigint unsigned', false, null]]),
            'title' => ['varchar(255)', false, null],
            'description' => ['text', true, null],
            ...($isTask ? [] : ['position' => ['smallint unsigned', false, null]]),
            'status' => ['varchar(20)', false, 'pending'],
            ...($isTask ? ['priority' => ['tinyint unsigned', false, '2']] : []),
            'estimated_minutes' => ['smallint unsigned', true, null],
            'deadline_at' => ['datetime', true, null],
            'completed_at' => ['datetime', true, null],
            'created_at' => ['timestamp', true, null],
            'updated_at' => ['timestamp', true, null],
            'deleted_at' => ['timestamp', true, null],
        ]);
        $record = $model::factory()->create()->refresh();
        $minimal = $model::create($record->only($isTask ? ['user_id', 'title'] : ['task_id', 'title', 'position']))->refresh();
        $this->assertSame(TaskStatus::Pending, $minimal->status);
        $this->assertNull($record->estimated_minutes);
        $this->assertNull($record->deadline_at);
        $this->assertNull($record->completed_at);
        $this->assertNull($record->description);
        if ($isTask) {
            $this->assertSame(TaskPriority::Normal, $minimal->priority);
            $this->assertNull($record->subject);
        } else {
            $this->assertSame(1, $record->position);
        }
        $record->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-05 12:00:00', 'deadline_at' => '2026-10-05 11:00:00', 'estimated_minutes' => 30, 'description' => 'Test description']);
        $record->refresh();
        $this->assertSame(TaskStatus::Completed, $record->status);
        $this->assertSame(30, $record->estimated_minutes);
        $this->assertSame('Test description', $record->description);
        foreach (['deadline_at', 'completed_at'] as $column) {
            $this->assertInstanceOf(Carbon::class, $record->$column);
            $this->assertSame('UTC', $record->$column->timezoneName);
        }
        $record->delete();
        $this->assertSoftDeleted($record);
        $this->assertNull($model::find($record->id));
        $this->assertTrue($model::withTrashed()->find($record->id)->is($record));
        $this->assertDatabaseHas($table, ['id' => $record->id]);
        $record->restore();
        $this->assertNotSoftDeleted($record);
    }

    public static function taskModels(): array
    {
        return [[Task::class], [Subtask::class]];
    }

    public function test_enums_match_exact_vocabulary(): void
    {
        $this->assertSame(['pending', 'completed'], array_column(TaskStatus::cases(), 'value'));
        $this->assertSame([1, 2, 3], array_column(TaskPriority::cases(), 'value'));
    }

    #[DataProvider('priorities')]
    public function test_approved_priorities_are_accepted(int $priority): void
    {
        $task = Task::factory()->create(['priority' => $priority])->refresh();
        $this->assertSame(TaskPriority::from($priority), $task->priority);
    }

    public static function priorities(): array
    {
        return [[1], [2], [3]];
    }

    #[DataProvider('invalidValues')]
    public function test_mysql_rejects_invalid_values_on_insert_and_update(string $model, array $changes, int $error, string|array|null $constraint): void
    {
        $this->assertInsertAndUpdateRejected($model::factory()->create(), $changes, $error, $constraint);
    }

    public static function invalidValues(): array
    {
        $cases = [];
        foreach (['tasks' => Task::class, 'subtasks' => Subtask::class] as $table => $model) {
            $statusChecks = [$table.'_valid_status', $table.'_completion_consistency'];
            $cases[$table.' unsupported status'] = [$model, ['status' => 'overdue'], 3819, $statusChecks];
            $cases[$table.' empty status'] = [$model, ['status' => ''], 3819, $statusChecks];
            $cases[$table.' zero estimate'] = [$model, ['estimated_minutes' => 0], 3819, $table.'_positive_estimate'];
            $cases[$table.' negative estimate'] = [$model, ['estimated_minutes' => -1], 1264, null];
            $cases[$table.' completed without timestamp'] = [$model, ['status' => 'completed'], 3819, $table.'_completion_consistency'];
            $cases[$table.' pending with timestamp'] = [$model, ['completed_at' => '2026-10-05 12:00:00'], 3819, $table.'_completion_consistency'];
        }

        return [
            ...$cases,
            [Task::class, ['priority' => 0], 3819, 'tasks_valid_priority'],
            [Task::class, ['priority' => 4], 3819, 'tasks_valid_priority'],
            [Task::class, ['priority' => -1], 1264, null],
            [Subtask::class, ['position' => 0], 3819, 'subtasks_positive_position'],
            [Subtask::class, ['position' => -1], 1264, null],
        ];
    }

    #[DataProvider('taskModels')]
    public function test_positive_estimates_and_pending_transition_are_accepted(string $model): void
    {
        $record = $model::factory()->create(['estimated_minutes' => 65535, 'status' => TaskStatus::Completed, 'completed_at' => '2026-10-05 12:00:00'])->refresh();
        $this->assertSame(65535, $record->estimated_minutes);
        $record->update(['status' => TaskStatus::Pending, 'completed_at' => null, 'estimated_minutes' => 1]);
        $this->assertSame(TaskStatus::Pending, $record->refresh()->status);
        $this->assertSame(1, $record->estimated_minutes);
    }

    public function test_relationships_owner_isolation_and_subject_set_null(): void
    {
        $subject = Subject::factory()->create();
        $task = Task::factory()->for($subject->user)->for($subject)->create();
        $subtask = Subtask::factory()->for($task)->create(['position' => 65535]);
        $other = Task::factory()->create();
        $this->assertTrue($task->user->is($subject->user));
        $this->assertTrue($task->subject->is($subject));
        $this->assertSame([$task->id], $subject->tasks->modelKeys());
        $this->assertSame([$task->id], $subject->user->tasks->modelKeys());
        $this->assertSame([$other->id], $other->user->tasks->modelKeys());
        $this->assertTrue($subtask->task->is($task));
        $this->assertSame([$subtask->id], $task->subtasks->modelKeys());
        $this->assertSame(65535, $subtask->position);
        $subject->delete();
        $this->assertNull($task->refresh()->subject_id);
        $this->assertNull($task->subject);
        $this->assertModelExists($subtask);
    }

    public function test_task_soft_delete_does_not_implement_future_service_cleanup_and_hard_delete_cascades(): void
    {
        $subtask = Subtask::factory()->create();
        $task = $subtask->task;
        $task->delete();
        // Future Task Service must orchestrate subtask/session/reminder cleanup.
        $this->assertNull($subtask->refresh()->deleted_at);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $task->forceDelete();
        $this->assertModelMissing($subtask);
    }

    public function test_user_hard_delete_cascades_tasks_and_subtasks(): void
    {
        $subtask = Subtask::factory()->create();
        $task = $subtask->task;
        $other = Task::factory()->create();
        $task->user->delete();
        $this->assertModelMissing($task);
        $this->assertModelMissing($subtask);
        $this->assertModelExists($other);
    }

    #[DataProvider('missingTargets')]
    public function test_missing_foreign_keys_are_rejected(string $model, string $column, string $target): void
    {
        $missing = $target::factory()->create();
        $target === Task::class ? $missing->forceDelete() : $missing->delete();
        $record = $model::factory()->create();
        $this->assertInsertAndUpdateRejected($record, [$column => $missing->id], 1452, $record->getTable().'_'.$column.'_foreign');
    }

    public static function missingTargets(): array
    {
        return [[Task::class, 'user_id', User::class], [Task::class, 'subject_id', Subject::class], [Subtask::class, 'task_id', Task::class]];
    }

    public function test_cross_owner_subject_and_later_subtask_deadline_are_only_physical_capabilities(): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-05 12:00:00']);
        $subject = Subject::factory()->create();
        // Invalid domain associations/deadline: future Services must reject these.
        $task->update(['subject_id' => $subject->id]);
        $subtask = Subtask::factory()->for($task)->create(['deadline_at' => '2026-10-06 12:00:00']);
        $this->assertNotSame($task->user_id, $subject->user_id);
        $this->assertTrue($subtask->deadline_at->gt($task->deadline_at));
    }

    public function test_keys_indexes_and_exact_named_checks(): void
    {
        $this->assertForeignKeys('tasks', ['user_id' => ['users', 'cascade'], 'subject_id' => ['subjects', 'set null']]);
        $this->assertForeignKeys('subtasks', ['task_id' => ['tasks', 'cascade']]);
        $this->assertIndex('tasks', 'tasks_user_id_status_deadline_at_index', ['user_id', 'status', 'deadline_at']);
        $this->assertIndex('tasks', 'tasks_user_id_subject_id_status_index', ['user_id', 'subject_id', 'status']);
        $this->assertIndex('subtasks', 'subtasks_task_id_position_index', ['task_id', 'position']);
        $this->assertIndex('subtasks', 'subtasks_task_id_status_deadline_at_index', ['task_id', 'status', 'deadline_at']);
        $this->assertEnforcedChecks('tasks', ['tasks_valid_status', 'tasks_valid_priority', 'tasks_positive_estimate', 'tasks_completion_consistency']);
        $this->assertEnforcedChecks('subtasks', ['subtasks_positive_position', 'subtasks_valid_status', 'subtasks_positive_estimate', 'subtasks_completion_consistency']);
    }
}
