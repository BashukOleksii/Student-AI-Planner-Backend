<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactSubtaskReorderTest extends TestCase
{
    use RefreshDatabase;

    private function url(Task $task): string
    {
        return '/api/tasks/'.$task->id.'/subtasks/reorder';
    }

    #[DataProvider('validPermutations')]
    public function test_valid_permutations_normalize_positions_and_preserve_other_fields(array $positions, array $indices): void
    {
        $task = Task::factory()->create()->refresh();
        $parentBefore = $task->getAttributes();
        $children = [];
        foreach ($positions as $i => $position) {
            $children[] = Subtask::factory()->for($task)->create(['position' => $position, 'description' => 'Keep', 'estimated_minutes' => 30, 'deadline_at' => '2026-10-10 12:00:00', 'status' => $i === 0 ? TaskStatus::Completed : TaskStatus::Pending, 'completed_at' => $i === 0 ? '2026-10-09 12:00:00' : null])->refresh();
        }
        $before = array_map(fn ($child) => $child->getAttributes(), $children);
        $ids = array_map(fn ($i) => $children[$i]->id, $indices);
        $response = $this->actingAs($task->user, 'web')->postJson($this->url($task), ['subtask_ids' => $ids, 'position' => 65535])->assertOk();
        $this->assertSame($ids, array_column($response->json('data'), 'id'));
        $this->assertSame($ids === [] ? [] : range(1, count($ids)), array_column($response->json('data'), 'position'));
        foreach ($children as $i => $child) {
            $this->assertSame(Arr::except($before[$i], ['position', 'updated_at']), Arr::except($child->refresh()->getAttributes(), ['position', 'updated_at']));
        }
        $this->assertSame($parentBefore, $task->refresh()->getAttributes());
        $this->getJson('/api/tasks/'.$task->id.'/subtasks')->assertOk()->assertExactJson($response->json());
        if ($ids !== []) {
            $this->assertSame(['id', 'task_id', 'title', 'description', 'position', 'status', 'estimated_minutes', 'deadline_at', 'effective_deadline_at', 'completed_at'], array_keys($response->json('data.0')));
        }
    }

    public static function validPermutations(): array
    {
        return ['empty' => [[], []]];
    }

    public function test_identical_dense_order_is_idempotent_without_update_and_create_still_appends(): void
    {
        $task = Task::factory()->create();
        $first = Subtask::factory()->for($task)->create(['position' => 1]);
        $second = Subtask::factory()->for($task)->create(['position' => 2]);
        $this->actingAs($task->user, 'web')->postJson($this->url($task), ['subtask_ids' => [$second->id, $first->id]])->assertOk();
        $before = [$first->refresh()->getAttributes(), $second->refresh()->getAttributes()];
        $this->travel(1)->days();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson($this->url($task), ['subtask_ids' => [$second->id, $first->id]])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], array_values(array_filter($queries, fn ($query) => str_starts_with($query['query'], 'update '))));
        $this->assertSame($before, [$first->refresh()->getAttributes(), $second->refresh()->getAttributes()]);
        $this->postJson('/api/tasks/'.$task->id.'/subtasks', ['title' => 'Appended'])->assertCreated()->assertJsonPath('data.position', 3);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_request_shapes_and_ids_return_validation_without_writes(array $values, string $error): void
    {
        $child = Subtask::factory()->create()->refresh();
        $before = $child->getAttributes();
        $this->actingAs($child->task->user, 'web')->postJson($this->url($child->task), $values)->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertSame($before, $child->refresh()->getAttributes());
    }

    public static function invalidInputs(): array
    {
        return ['missing' => [[], 'subtask_ids']];
    }

    #[DataProvider('invalidJson')]
    public function test_json_objects_and_malformed_values_cannot_masquerade_as_arrays(string $content): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->call('POST', $this->url($task), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $content)->assertUnprocessable();
        $this->assertDatabaseCount('subtasks', 0);
    }

    public static function invalidJson(): array
    {
        return [0 => ['{"subtask_ids":{}}']];
    }

    public function test_non_json_permutation_is_rejected(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->post($this->url($task), ['subtask_ids' => []], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('subtask_ids');
    }

    public function test_integral_json_float_matching_an_existing_id_is_rejected(): void
    {
        $child = Subtask::factory()->create()->refresh();
        $before = $child->getAttributes();
        $this->actingAs($child->task->user, 'web')->call('POST', $this->url($child->task), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['subtask_ids' => [(float) $child->id]], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))->assertUnprocessable()->assertJsonValidationErrors('subtask_ids.0');
        $this->assertSame($before, $child->refresh()->getAttributes());
    }
}
