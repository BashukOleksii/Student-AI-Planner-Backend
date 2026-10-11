<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\SubtaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SubtaskReorderTest extends TestCase
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
            $children[] = Subtask::factory()->for($task)->create(['position' => $position, 'description' => 'Keep', 'estimated_minutes' => 30,
                'deadline_at' => '2026-10-10 12:00:00', 'status' => $i === 0 ? TaskStatus::Completed : TaskStatus::Pending,
                'completed_at' => $i === 0 ? '2026-10-09 12:00:00' : null])->refresh();
        }
        $before = array_map(fn ($child) => $child->getAttributes(), $children);
        $ids = array_map(fn ($i) => $children[$i]->id, $indices);
        $response = $this->actingAs($task->user, 'web')->postJson($this->url($task), ['subtask_ids' => $ids, 'user_id' => 999999,
            'task_id' => 999999, 'position' => 65535])->assertOk();
        $this->assertSame($ids, array_column($response->json('data'), 'id'));
        $this->assertSame($ids === [] ? [] : range(1, count($ids)), array_column($response->json('data'), 'position'));
        foreach ($children as $i => $child) {
            $this->assertSame(Arr::except($before[$i], ['position', 'updated_at']),
                Arr::except($child->refresh()->getAttributes(), ['position', 'updated_at']));
        }
        $this->assertSame($parentBefore, $task->refresh()->getAttributes());
        $this->getJson('/api/tasks/'.$task->id.'/subtasks')->assertOk()->assertExactJson($response->json());
        if ($ids !== []) {
            $this->assertSame(['id', 'task_id', 'title', 'description', 'position', 'status', 'estimated_minutes', 'deadline_at', 'completed_at'],
                array_keys($response->json('data.0')));
        }
    }

    public static function validPermutations(): array
    {
        return [
            'empty' => [[], []], 'one' => [[20], [0]], 'two reverse' => [[1, 2], [1, 0]],
            'three reverse' => [[1, 2, 3], [2, 1, 0]], 'arbitrary' => [[1, 2, 3], [1, 2, 0]],
            'gaps' => [[5, 10, 20], [1, 0, 2]], 'duplicates' => [[3, 3, 3], [2, 0, 1]],
            'maximum position' => [[65535, 7, 100], [0, 2, 1]],
        ];
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
        $cases = [
            'missing' => [[], 'subtask_ids'], 'null' => [['subtask_ids' => null], 'subtask_ids'],
            'string' => [['subtask_ids' => '1,2'], 'subtask_ids'], 'scalar' => [['subtask_ids' => 1], 'subtask_ids'],
            'not list' => [['subtask_ids' => ['other' => 1]], 'subtask_ids'],
            'duplicate' => [['subtask_ids' => [1, 1]], 'subtask_ids.0'],
        ];
        foreach (['1', 1.5, 0, -1, true, null, [], ['id' => 1]] as $i => $value) {
            $cases['invalid element '.$i] = [['subtask_ids' => [$value]], 'subtask_ids.0'];
        }

        return $cases;
    }

    #[DataProvider('invalidJson')]
    public function test_json_objects_and_malformed_values_cannot_masquerade_as_arrays(string $content): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->call('POST', $this->url($task), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $content)->assertUnprocessable();
        $this->assertDatabaseCount('subtasks', 0);
    }

    public static function invalidJson(): array
    {
        return [['{"subtask_ids":{}}'], ['{"subtask_ids":{"0":1}}'], ['{"subtask_ids":[1.0]}'], ['{"subtask_ids":'], ['[]']];
    }

    public function test_non_json_permutation_is_rejected(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->post($this->url($task), ['subtask_ids' => []], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('subtask_ids');
    }

    public function test_integral_json_float_matching_an_existing_id_is_rejected(): void
    {
        $child = Subtask::factory()->create()->refresh();
        $before = $child->getAttributes();
        $this->actingAs($child->task->user, 'web')->call('POST', $this->url($child->task), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['subtask_ids' => [(float) $child->id]], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))
            ->assertUnprocessable()->assertJsonValidationErrors('subtask_ids.0');
        $this->assertSame($before, $child->refresh()->getAttributes());
    }

    public function test_unknown_foreign_other_owned_and_deleted_ids_share_one_safe_error(): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create()->refresh();
        $foreign = Subtask::factory()->create()->refresh();
        $otherTask = Task::factory()->for($task->user)->create();
        $other = Subtask::factory()->for($otherTask)->create()->refresh();
        $deleted = Subtask::factory()->for($task)->create();
        $deleted->delete();
        $records = [$child, $foreign, $other, $deleted];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web');
        $errors = [];
        foreach ([999999, $foreign->id, $other->id, $deleted->id] as $id) {
            $errors[] = $this->postJson($this->url($task), ['subtask_ids' => [$id]])->assertUnprocessable()
                ->assertJsonValidationErrors('subtask_ids')->json();
        }
        foreach ($errors as $error) {
            $this->assertSame($errors[0], $error);
        }
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }

    public function test_empty_partial_extra_and_stale_permutations_are_rejected(): void
    {
        $task = Task::factory()->create();
        $first = Subtask::factory()->for($task)->create(['position' => 3])->refresh();
        $second = Subtask::factory()->for($task)->create(['position' => 10])->refresh();
        $this->actingAs($task->user, 'web');
        foreach ([[], [$first->id], [$first->id, $second->id, 999999]] as $ids) {
            $this->postJson($this->url($task), ['subtask_ids' => $ids])->assertUnprocessable()->assertJsonValidationErrors('subtask_ids');
        }
        $task->load('subtasks');
        $third = app(SubtaskService::class)->create($task->user, $task, ['title' => 'New child']);
        try {
            app(SubtaskService::class)->reorder($task->user, $task, [$second->id, $first->id]);
            $this->fail('The newly persisted active set must be used.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subtask_ids', $exception->errors());
        }
        $this->assertSame([3, 10, 11], [$first->refresh()->position, $second->refresh()->position, $third->refresh()->position]);
    }

    public function test_authentication_and_parent_authorization_precede_body_validation(): void
    {
        $task = Task::factory()->create();
        $this->postJson($this->url($task), [])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->postJson($this->url($task), ['subtask_ids' => null])->assertNotFound();
        $owned = Task::factory()->for(auth()->user())->create();
        $owned->delete();
        $this->postJson($this->url($owned), [])->assertNotFound();
        $this->postJson('/api/tasks/999999/subtasks/reorder', [])->assertNotFound();
    }

    public function test_direct_service_enforces_owner_scope_and_permutation_types(): void
    {
        $child = Subtask::factory()->create()->refresh();
        $task = $child->task;
        $before = $child->getAttributes();
        $foreignUser = User::factory()->create();
        $task->user_id = $foreignUser->id;
        try {
            app(SubtaskService::class)->reorder($foreignUser, $task, [$child->id]);
            $this->fail('The forged model must not bypass ownership.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $child->refresh()->getAttributes());
        }
        $task->refresh();
        foreach ([[(string) $child->id], [$child->id, $child->id], ['key' => $child->id]] as $ids) {
            try {
                app(SubtaskService::class)->reorder($task->user, $task, $ids);
                $this->fail('The Service must reject malformed permutations.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('subtask_ids', $exception->errors());
            }
        }
        $this->assertSame($before, $child->refresh()->getAttributes());
    }

    public function test_parent_related_records_and_soft_deleted_history_are_untouched(): void
    {
        $task = Task::factory()->create();
        $first = Subtask::factory()->for($task)->create(['position' => 1]);
        $second = Subtask::factory()->for($task)->create(['position' => 2]);
        $deleted = Subtask::factory()->for($task)->create(['position' => 65535]);
        $deleted->delete();
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $first->id]);
        $reminder = Reminder::factory()->for($task->user)->create(['subtask_id' => $first->id]);
        $records = [$task, $deleted, $session, $reminder];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web')->postJson($this->url($task), ['subtask_ids' => [$second->id, $first->id]])->assertOk();
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }

    public function test_later_write_failure_rolls_back_all_updates_and_owner_parent_child_locks_precede_writes(): void
    {
        $task = Task::factory()->create();
        $user = $task->user;
        $children = Subtask::factory()->count(3)->for($task)->create(['position' => 10]);
        $before = $children->map(fn ($child) => $child->refresh()->getAttributes())->all();
        $level = DB::transactionLevel();
        $writes = 0;
        $queries = [];
        DB::listen(function ($query) use (&$queries, &$writes): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
            if (str_starts_with($query->sql, 'update `subtasks`') && ++$writes === 2) {
                throw new RuntimeException('Deliberate later position failure');
            }
        });
        try {
            app(SubtaskService::class)->reorder($user, $task, array_reverse($children->modelKeys()));
            $this->fail('The second mutation must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate later position failure', $exception->getMessage());
        }
        foreach (['users', 'tasks', 'subtasks'] as $i => $table) {
            $this->assertStringContainsString('from `'.$table.'`', $queries[$i]['sql']);
            $this->assertStringContainsString('for update', $queries[$i]['sql']);
            $this->assertSame($level + 1, $queries[$i]['level']);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame(2, $writes);
        foreach ($children as $i => $child) {
            $this->assertSame($before[$i], $child->refresh()->getAttributes());
        }
    }

    public function test_active_count_over_position_capacity_is_rejected_without_mutation(): void
    {
        $task = Task::factory()->create();
        $row = ['task_id' => $task->id, 'title' => 'Capacity', 'position' => 1];
        for ($remaining = 65536; $remaining > 0; $remaining -= $batchSize) {
            $batchSize = min(1000, $remaining);
            DB::table('subtasks')->insert(array_fill(0, $batchSize, $row));
        }
        $this->actingAs($task->user, 'web')->postJson($this->url($task), ['subtask_ids' => []])->assertUnprocessable()
            ->assertJsonValidationErrors('subtask_ids');
        $this->assertSame(65536, $task->subtasks()->where('position', 1)->count());
        $this->assertSame(0, $task->subtasks()->whereNotNull('updated_at')->count());
    }
}
