<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactSubtaskApiTest extends TestCase
{
    use RefreshDatabase;

    private function url(Task $task, ?Subtask $subtask = null): string
    {
        return '/api/tasks/'.$task->id.'/subtasks'.($subtask === null ? '' : '/'.$subtask->id);
    }

    public function test_minimal_create_exact_resource_and_trusted_internal_fields_under_completed_parent(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00'])->refresh();
        $before = $task->getAttributes();
        $other = Task::factory()->create();
        $response = $this->actingAs($task->user, 'web')->postJson($this->url($task), ['title' => 'Підготувати звіт', 'position' => 65535, 'status' => 'completed', 'completed_at' => '2000-01-01T00:00:00Z'])->assertCreated();
        $subtask = Subtask::sole();
        $response->assertExactJson(['data' => ['id' => $subtask->id, 'task_id' => $task->id, 'title' => 'Підготувати звіт', 'description' => null, 'position' => 1, 'status' => 'pending', 'estimated_minutes' => null, 'deadline_at' => null, 'effective_deadline_at' => null, 'completed_at' => null]]);
        $this->assertNotSame(999999, $subtask->id);
        $this->assertNull($subtask->deleted_at);
        $this->assertNotSame('2000-01-01', $subtask->getRawOriginal('created_at'));
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->getJson($this->url($task, $subtask))->assertOk()->assertExactJson($response->json());
    }

    #[DataProvider('validValues')]
    public function test_full_create_valid_values_and_patch(string $deadline, int $estimate): void
    {
        $task = Task::factory()->create();
        $description = str_repeat('Я', 32767).'a';
        $this->actingAs($task->user, 'web')->postJson($this->url($task), ['title' => str_repeat('Я', 255), 'description' => $description, 'estimated_minutes' => $estimate, 'deadline_at' => $deadline])->assertCreated()->assertJsonPath('data.description', $description)->assertJsonPath('data.estimated_minutes', $estimate)->assertJsonPath('data.deadline_at', $deadline);
        $subtask = Subtask::sole();
        $this->patchJson($this->url($task, $subtask), ['estimated_minutes' => $estimate, 'deadline_at' => $deadline])->assertOk()->assertJsonPath('data.deadline_at', $deadline)->assertJsonPath('data.estimated_minutes', $estimate);
    }

    public static function validValues(): array
    {
        return [0 => ['1000-01-01T00:00:00Z', 1]];
    }

    public function test_create_accepts_null_optionals_and_requires_title(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->postJson($this->url($task), [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('subtasks', 0);
        $this->postJson($this->url($task), ['title' => 'Test', 'description' => null, 'deadline_at' => null, 'estimated_minutes' => null])->assertCreated()->assertJsonPath('data.description', null)->assertJsonPath('data.deadline_at', null)->assertJsonPath('data.estimated_minutes', null);
    }

    public function test_positions_append_from_active_maximum_and_ignore_deleted_history(): void
    {
        $task = Task::factory()->create();
        $deleted = Subtask::factory()->for($task)->create(['position' => 65535]);
        $deleted->delete();
        $this->actingAs($task->user, 'web')->postJson($this->url($task), ['title' => 'First'])->assertCreated()->assertJsonPath('data.position', 1);
        Subtask::factory()->for($task)->create(['position' => 10]);
        $this->postJson($this->url($task), ['title' => 'Next'])->assertCreated()->assertJsonPath('data.position', 11);
        $this->postJson($this->url($task), ['title' => 'Next again'])->assertCreated()->assertJsonPath('data.position', 12);
        $this->assertSoftDeleted($deleted);
        $this->assertSame(65535, $deleted->refresh()->position);
    }

    public function test_last_position_is_usable_then_capacity_failure_is_controlled_without_renumbering(): void
    {
        $subtask = Subtask::factory()->create(['position' => 65534])->refresh();
        $before = $subtask->getAttributes();
        $task = $subtask->task;
        $this->actingAs($task->user, 'web')->postJson($this->url($task), ['title' => 'Last'])->assertCreated()->assertJsonPath('data.position', 65535);
        $this->postJson($this->url($task), ['title' => 'Overflow'])->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->assertDatabaseCount('subtasks', 2);
        $this->assertSame($before, $subtask->refresh()->getAttributes());
    }

    public function test_collection_is_position_then_id_ordered_and_read_only(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->getJson($this->url($task))->assertOk()->assertExactJson(['data' => []]);
        $later = Subtask::factory()->for($task)->create(['position' => 3]);
        $first = Subtask::factory()->for($task)->create(['position' => 1]);
        $tie = Subtask::factory()->for($task)->create(['position' => 1]);
        $deleted = Subtask::factory()->for($task)->create(['position' => 2]);
        $deleted->delete();
        Subtask::factory()->create();
        $records = [$task, $later, $first, $tie, $deleted];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $response = $this->getJson($this->url($task))->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([$first->id, $tie->id, $later->id], array_column($response->json('data'), 'id'));
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }
}
