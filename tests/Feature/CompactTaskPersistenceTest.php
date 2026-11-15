<?php

namespace Tests\Feature;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

#[Group('migration')]
#[Group('mysql')]
class CompactTaskPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, RefreshDatabase;

    #[DataProvider('taskModels')]
    public function test_exact_schema_defaults_casts_and_soft_deletion(string $model): void
    {
        $isTask = $model === Task::class;
        $table = $isTask ? 'tasks' : 'subtasks';
        $this->assertTableDefinition($table, ['id' => ['bigint unsigned', false, null], ...$isTask ? ['user_id' => ['bigint unsigned', false, null], 'subject_id' => ['bigint unsigned', true, null]] : ['task_id' => ['bigint unsigned', false, null]], 'title' => ['varchar(255)', false, null], 'description' => ['text', true, null], ...$isTask ? [] : ['position' => ['smallint unsigned', false, null]], 'status' => ['varchar(20)', false, 'pending'], ...$isTask ? ['priority' => ['tinyint unsigned', false, '2']] : [], 'estimated_minutes' => ['smallint unsigned', true, null], 'deadline_at' => ['datetime', true, null], 'completed_at' => ['datetime', true, null], 'created_at' => ['timestamp', true, null], 'updated_at' => ['timestamp', true, null], 'deleted_at' => ['timestamp', true, null]]);
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
            $this->assertInstanceOf(Carbon::class, $record->{$column});
            $this->assertSame('UTC', $record->{$column}->timezoneName);
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
        return [0 => ['App\Models\Task']];
    }

    public function test_enums_match_exact_vocabulary(): void
    {
        $this->assertSame(['pending', 'completed', 'missed'], array_column(TaskStatus::cases(), 'value'));
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
        return [0 => [1]];
    }

    #[DataProvider('invalidValues')]
    public function test_mysql_rejects_invalid_values_on_insert_and_update(string $model, array $changes, int $error, string|array|null $constraint): void
    {
        $this->assertInsertAndUpdateRejected($model::factory()->create(), $changes, $error, $constraint);
    }

    public static function invalidValues(): array
    {
        return ['tasks unsupported status' => ['App\Models\Task', ['status' => 'overdue'], 3819, [0 => 'tasks_valid_status', 1 => 'tasks_completion_consistency']]];
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

    public function test_task_soft_delete_does_not_implement_future_service_cleanup_and_hard_delete_cascades(): void
    {
        $subtask = Subtask::factory()->create();
        $task = $subtask->task;
        $task->delete();
        $this->assertNull($subtask->refresh()->deleted_at);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $task->forceDelete();
        $this->assertModelMissing($subtask);
    }
}
