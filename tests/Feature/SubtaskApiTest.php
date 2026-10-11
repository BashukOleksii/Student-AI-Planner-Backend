<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Tasks\SubtaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SubtaskApiTest extends TestCase
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
        $response = $this->actingAs($task->user, 'web')->postJson($this->url($task), [
            'title' => 'Підготувати звіт', 'task_id' => $other->id, 'user_id' => $other->user_id, 'id' => 999999,
            'position' => 65535, 'status' => 'completed', 'completed_at' => '2000-01-01T00:00:00Z',
            'created_at' => '2000-01-01', 'updated_at' => '2000-01-01', 'deleted_at' => '2000-01-01',
        ])->assertCreated();
        $subtask = Subtask::sole();
        $response->assertExactJson(['data' => [
            'id' => $subtask->id, 'task_id' => $task->id, 'title' => 'Підготувати звіт', 'description' => null,
            'position' => 1, 'status' => 'pending', 'estimated_minutes' => null, 'deadline_at' => null, 'completed_at' => null,
        ]]);
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
        $this->actingAs($task->user, 'web')->postJson($this->url($task), [
            'title' => str_repeat('Я', 255), 'description' => $description, 'estimated_minutes' => $estimate, 'deadline_at' => $deadline,
        ])->assertCreated()->assertJsonPath('data.description', $description)->assertJsonPath('data.estimated_minutes', $estimate)
            ->assertJsonPath('data.deadline_at', $deadline);
        $subtask = Subtask::sole();
        $this->patchJson($this->url($task, $subtask), ['estimated_minutes' => $estimate, 'deadline_at' => $deadline])
            ->assertOk()->assertJsonPath('data.deadline_at', $deadline)->assertJsonPath('data.estimated_minutes', $estimate);
    }

    public static function validValues(): array
    {
        return [['1000-01-01T00:00:00Z', 1], ['9999-12-31T23:59:59Z', 65535], ['2024-02-29T12:34:56Z', 30]];
    }

    public function test_create_accepts_null_optionals_and_requires_title(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->postJson($this->url($task), [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('subtasks', 0);
        $this->postJson($this->url($task), ['title' => 'Test', 'description' => null, 'deadline_at' => null, 'estimated_minutes' => null])
            ->assertCreated()->assertJsonPath('data.description', null)->assertJsonPath('data.deadline_at', null)->assertJsonPath('data.estimated_minutes', null);
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
        $response = $this->getJson($this->url($task).'?task_id=999999&user_id=999999')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([$first->id, $tie->id, $later->id], array_column($response->json('data'), 'id'));
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_create_and_patch_have_no_partial_mutation(string $field, mixed $value): void
    {
        $subtask = Subtask::factory()->create()->refresh();
        $before = $subtask->getAttributes();
        $task = $subtask->task;
        $this->actingAs($task->user, 'web')->postJson($this->url($task), ['title' => 'Test', $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($task, $subtask), ['description' => 'Must not persist', $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $subtask->refresh()->getAttributes());
        $this->assertDatabaseCount('subtasks', 1);
    }

    public static function invalidValues(): array
    {
        $cases = [];
        foreach (['title' => [null, '', '   ', 42, [], str_repeat('Я', 256)],
            'description' => [42, [], str_repeat('Я', 32768)],
            'estimated_minutes' => ['', 0, -1, 65536, '30', 1.5, true, []],
            'deadline_at' => ['', ' ', '2026-02-29T00:00:00Z', '2026-10-05T24:00:00Z', '2026-10-05T12:00:00+00:00',
                '2026-10-05T12:00:00.000Z', '2026-10-05 12:00:00', '2026-10-05T12:00:00',
                ' 2026-10-05T12:00:00Z', "2026-10-05T12:00:00Z\n", '0999-12-31T23:59:59Z', '10000-01-01T00:00:00Z', 42, []],
        ] as $field => $values) {
            foreach ($values as $i => $value) {
                $cases[$field.' '.$i] = [$field, $value];
            }
        }

        return $cases;
    }

    public function test_partial_empty_and_null_patch_preserve_lifecycle_positions_and_related_records(): void
    {
        $subtask = Subtask::factory()->create(['title' => 'Original', 'description' => 'Keep', 'position' => 8,
            'estimated_minutes' => 30, 'deadline_at' => '2026-10-10 12:00:00', 'status' => TaskStatus::Completed,
            'completed_at' => '2026-10-09 12:00:00']);
        $task = $subtask->task;
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $subtask->id]);
        $reminder = Reminder::factory()->for($task->user)->create(['subtask_id' => $subtask->id]);
        $records = [$task, $session, $reminder];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web');
        $response = $this->patchJson($this->url($task, $subtask), ['title' => 'Edited', 'status' => 'pending', 'completed_at' => null,
            'position' => 1, 'task_id' => 999999, 'deleted_at' => '2000-01-01'])->assertOk()->assertExactJson(['data' => [
                'id' => $subtask->id, 'task_id' => $task->id, 'title' => 'Edited', 'description' => 'Keep', 'position' => 8,
                'status' => 'completed', 'estimated_minutes' => 30, 'deadline_at' => '2026-10-10T12:00:00Z', 'completed_at' => '2026-10-09T12:00:00Z',
            ]]);
        $this->patchJson($this->url($task, $subtask), [])->assertOk()->assertExactJson($response->json());
        $this->patchJson($this->url($task, $subtask), ['description' => null, 'estimated_minutes' => null, 'deadline_at' => null])
            ->assertOk()->assertJsonPath('data.description', null)->assertJsonPath('data.estimated_minutes', null)->assertJsonPath('data.deadline_at', null);
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }

    #[DataProvider('deadlineBounds')]
    public function test_deadline_bound_on_create_and_patch(?string $parentDeadline, ?string $deadline, bool $allowed): void
    {
        $task = Task::factory()->create(['deadline_at' => $parentDeadline]);
        $subtask = Subtask::factory()->for($task)->create()->refresh();
        $before = $subtask->getAttributes();
        $parentBefore = $task->refresh()->getAttributes();
        $this->actingAs($task->user, 'web');
        $create = $this->postJson($this->url($task), ['title' => 'Created', 'deadline_at' => $deadline]);
        $patch = $this->patchJson($this->url($task, $subtask), ['title' => 'Edited', 'deadline_at' => $deadline]);
        if ($allowed) {
            $create->assertCreated()->assertJsonPath('data.deadline_at', $deadline);
            $patch->assertOk()->assertJsonPath('data.deadline_at', $deadline);
        } else {
            $create->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
            $patch->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
            $this->assertSame($before, $subtask->refresh()->getAttributes());
            $this->assertDatabaseCount('subtasks', 1);
        }
        $this->assertSame($parentBefore, $task->refresh()->getAttributes());
    }

    public static function deadlineBounds(): array
    {
        return [
            ['2026-10-05 12:00:00', '2026-10-04T12:00:00Z', true],
            ['2026-10-05 12:00:00', '2026-10-05T12:00:00Z', true],
            ['2026-10-05 12:00:00', '2026-10-06T12:00:00Z', false],
            [null, '2020-10-05T12:00:00Z', true], ['2026-10-05 12:00:00', null, true],
        ];
    }

    #[DataProvider('repairs')]
    public function test_legacy_invalid_deadline_requires_explicit_repair(?string $repair): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-05 12:00:00']);
        $subtask = Subtask::factory()->for($task)->create(['deadline_at' => '2026-10-06 12:00:00'])->refresh();
        $before = $subtask->getAttributes();
        $this->actingAs($task->user, 'web')->patchJson($this->url($task, $subtask), ['title' => 'Unrelated'])
            ->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
        $this->assertSame($before, $subtask->refresh()->getAttributes());
        $this->patchJson($this->url($task, $subtask), ['deadline_at' => $repair])->assertOk()->assertJsonPath('data.deadline_at', $repair);
    }

    public static function repairs(): array
    {
        return [[null], ['2026-10-05T12:00:00Z']];
    }

    public function test_stale_parent_and_child_models_reload_current_candidate_and_deadline(): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-10 12:00:00']);
        $subtask = Subtask::factory()->for($task)->create();
        $user = $task->user;
        Task::findOrFail($task->id)->update(['deadline_at' => '2026-10-08 12:00:00']);
        Subtask::findOrFail($subtask->id)->update(['description' => 'Latest', 'estimated_minutes' => 90,
            'deadline_at' => '2026-10-07 12:00:00', 'status' => TaskStatus::Completed, 'completed_at' => '2026-10-06 12:00:00']);
        $result = app(SubtaskService::class)->update($user, $task, $subtask, ['title' => 'Edited']);
        $this->assertSame('Latest', $result->description);
        $this->assertSame(90, $result->estimated_minutes);
        $this->assertSame('2026-10-07 12:00:00', $result->getRawOriginal('deadline_at'));
        $this->assertSame(TaskStatus::Completed, $result->status);
        $this->actingAs($user, 'web')->postJson($this->url($task), ['title' => 'Too late', 'deadline_at' => '2026-10-09T12:00:00Z'])
            ->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
    }

    public function test_utc_output_and_partial_update_ignore_server_and_profile_timezone(): void
    {
        $subtask = Subtask::factory()->create(['deadline_at' => '2026-10-05 12:00:00',
            'status' => TaskStatus::Completed, 'completed_at' => '2026-10-04 12:00:00']);
        $task = $subtask->task;
        $user = $task->user;
        $user->update(['timezone' => 'America/Los_Angeles']);
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $this->actingAs($user, 'web')->getJson($this->url($task, $subtask))->assertOk()->assertJsonPath('data.deadline_at', '2026-10-05T12:00:00Z')
                ->assertJsonPath('data.completed_at', '2026-10-04T12:00:00Z');
            $this->patchJson($this->url($task, $subtask), ['title' => 'UTC'])->assertOk()->assertJsonPath('data.deadline_at', '2026-10-05T12:00:00Z');
            $this->postJson($this->url($task), ['title' => 'UTC', 'deadline_at' => '2026-10-05T12:34:56Z'])
                ->assertCreated()->assertJsonPath('data.deadline_at', '2026-10-05T12:34:56Z');
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_parent_deadline_updates_remain_compatible_with_child_updates(): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-10 12:00:00']);
        $this->actingAs($task->user, 'web')->postJson($this->url($task), ['title' => 'Test', 'deadline_at' => '2026-10-09T12:00:00Z'])->assertCreated();
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-08T12:00:00Z'])->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
        $subtask = Subtask::sole();
        $this->patchJson($this->url($task, $subtask), ['deadline_at' => '2026-10-07T12:00:00Z'])->assertOk();
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-08T12:00:00Z'])->assertOk();
    }

    public function test_failed_insert_rolls_back_and_allocation_occurs_after_owner_parent_locks(): void
    {
        $task = Task::factory()->create()->refresh();
        $user = $task->user;
        $before = $task->getAttributes();
        $level = DB::transactionLevel();
        $queries = [];
        $fail = true;
        DB::listen(function ($query) use (&$queries, &$fail): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
            if ($fail && str_starts_with($query->sql, 'insert into `subtasks`')) {
                $fail = false;
                throw new RuntimeException('Deliberate mutation failure');
            }
        });
        try {
            app(SubtaskService::class)->create($user, $task, ['title' => 'Rollback']);
            $this->fail('The insert must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate mutation failure', $exception->getMessage());
        }
        foreach (['users', 'tasks', 'subtasks'] as $i => $table) {
            $this->assertStringContainsString('from `'.$table.'`', $queries[$i]['sql']);
            $this->assertStringContainsString('for update', $queries[$i]['sql']);
            $this->assertSame($level + 1, $queries[$i]['level']);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('subtasks', 0);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $result = app(SubtaskService::class)->create($user, $task, ['title' => 'Retry']);
        $this->assertSame(1, $result->position);
    }
}
