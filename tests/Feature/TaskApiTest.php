<?php

namespace Tests\Feature;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Subject;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_minimal_create_defaults_and_public_shape(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user, 'web')->postJson('/api/tasks', ['title' => 'Курсова робота'])->assertCreated();
        $task = Task::sole();
        $response->assertExactJson(['data' => [
            'id' => $task->id, 'subject_id' => null, 'title' => 'Курсова робота', 'description' => null,
            'status' => 'pending', 'priority' => 2, 'estimated_minutes' => null, 'deadline_at' => null, 'completed_at' => null,
        ]]);
        $this->assertSame($user->id, $task->user_id);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame(TaskPriority::Normal, $task->priority);
        $this->getJson('/api/tasks/'.$task->id)->assertOk()->assertExactJson($response->json());
    }

    public function test_owned_subject_and_all_optional_fields(): void
    {
        $subject = Subject::factory()->create();
        $this->actingAs($subject->user, 'web')->postJson('/api/tasks', [
            'title' => str_repeat('Я', 255), 'subject_id' => $subject->id, 'description' => 'Підготувати звіт',
            'estimated_minutes' => 45, 'deadline_at' => '2020-02-29T12:34:56Z', 'priority' => 3,
        ])->assertCreated()->assertJsonPath('data.subject_id', $subject->id)->assertJsonPath('data.priority', 3)
            ->assertJsonPath('data.description', 'Підготувати звіт')->assertJsonPath('data.deadline_at', '2020-02-29T12:34:56Z');
        $this->assertDatabaseHas('tasks', ['user_id' => $subject->user_id, 'deadline_at' => '2020-02-29 12:34:56', 'estimated_minutes' => 45]);
    }

    public function test_explicit_nullable_fields_can_be_null_on_create(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/tasks', [
            'title' => 'Test', 'subject_id' => null, 'description' => null, 'estimated_minutes' => null, 'deadline_at' => null,
        ])->assertCreated()->assertJsonPath('data.subject_id', null)->assertJsonPath('data.description', null)
            ->assertJsonPath('data.estimated_minutes', null)->assertJsonPath('data.deadline_at', null);
    }

    #[DataProvider('approvedNumbers')]
    public function test_approved_priority_and_estimate_bounds(string $field, int $value): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/tasks', ['title' => 'Test', $field => $value])
            ->assertCreated()->assertJsonPath('data.'.$field, $value);
        $this->patchJson('/api/tasks/'.Task::sole()->id, [$field => $value])->assertOk()->assertJsonPath('data.'.$field, $value);
    }

    public static function approvedNumbers(): array
    {
        return [['priority', 1], ['priority', 2], ['priority', 3], ['estimated_minutes', 1], ['estimated_minutes', 65535]];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_has_no_create_or_partial_patch_mutation(string $field, mixed $value): void
    {
        $task = Task::factory()->create()->refresh();
        $before = $task->getAttributes();
        $this->actingAs($task->user, 'web');
        $this->postJson('/api/tasks', ['title' => 'Test', $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/tasks/'.$task->id, ['description' => 'Must roll back', $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public static function invalidInput(): array
    {
        $cases = [];
        foreach (['title' => [null, '', '   ', 42, [], str_repeat('Я', 256)],
            'description' => [42, [], str_repeat('Я', 32768)],
            'subject_id' => ['', 0, -1, '1', 1.5, true, [], 999999],
            'priority' => [null, 0, 4, -1, '2', 2.5, true, [], 'normal'],
            'estimated_minutes' => ['', 0, -1, 65536, '30', 1.5, true, []],
            'deadline_at' => ['', ' ', '2026-02-29T12:00:00Z', '2026-02-30T12:00:00Z', '2026-13-01T12:00:00Z',
                '2026-10-05T24:00:00Z', '2026-10-05T12:60:00Z', '2026-10-05T12:00:60Z',
                '2026-10-05T12:00:00+00:00', '2026-10-05T12:00:00.000Z', '2026-10-05T12:00:00',
                '2026-10-05 12:00:00', '2026-10-05T12:00:00z', ' 2026-10-05T12:00:00Z',
                "2026-10-05T12:00:00Z\n", '0999-12-31T23:59:59Z', '10000-01-01T00:00:00Z', 42, []],
        ] as $field => $values) {
            foreach ($values as $i => $value) {
                $cases[$field.' '.$i] = [$field, $value];
            }
        }

        return $cases;
    }

    public function test_title_is_required_on_create(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/tasks', [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_integral_json_floats_are_not_strict_integers(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['subject_id', 'priority', 'estimated_minutes'] as $field) {
            $this->call('POST', '/api/tasks', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                '{"title":"Test","'.$field.'":1.0}')->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('tasks', 0);
    }

    #[DataProvider('validDeadlines')]
    public function test_mysql_datetime_boundaries_and_leap_date(string $value): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/tasks', ['title' => 'Test', 'deadline_at' => $value])
            ->assertCreated()->assertJsonPath('data.deadline_at', $value);
    }

    public static function validDeadlines(): array
    {
        return [['1000-01-01T00:00:00Z'], ['9999-12-31T23:59:59Z'], ['2024-02-29T23:59:59Z']];
    }

    public function test_text_capacity_is_measured_in_utf8_bytes(): void
    {
        $description = str_repeat('Я', 32767).'a';
        $this->assertSame(65535, strlen($description));
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/tasks', ['title' => 'Test', 'description' => $description])
            ->assertCreated()->assertJsonPath('data.description', $description);
    }

    public function test_partial_and_empty_patch_preserve_omitted_fields_and_completed_state(): void
    {
        $subject = Subject::factory()->create();
        $task = Task::factory()->for($subject->user)->for($subject)->create([
            'description' => 'Original', 'priority' => 1, 'estimated_minutes' => 120,
            'deadline_at' => '2026-10-05 12:00:00', 'status' => TaskStatus::Completed, 'completed_at' => '2026-10-05 11:00:00',
        ])->refresh();
        $this->actingAs($subject->user, 'web');
        $response = $this->patchJson('/api/tasks/'.$task->id, ['title' => 'Edited'])->assertOk()->assertExactJson(['data' => [
            'id' => $task->id, 'subject_id' => $subject->id, 'title' => 'Edited', 'description' => 'Original',
            'priority' => 1, 'estimated_minutes' => 120, 'deadline_at' => '2026-10-05T12:00:00Z',
            'status' => 'completed', 'completed_at' => '2026-10-05T11:00:00Z',
        ]]);
        $this->patchJson('/api/tasks/'.$task->id, [])->assertOk()->assertExactJson($response->json());
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
    }

    public function test_null_patch_clears_all_nullable_editable_fields(): void
    {
        $subject = Subject::factory()->create();
        $task = Task::factory()->for($subject->user)->for($subject)->create([
            'description' => 'Clear', 'estimated_minutes' => 10, 'deadline_at' => '2026-10-05 12:00:00',
        ]);
        $this->actingAs($subject->user, 'web')->patchJson('/api/tasks/'.$task->id, [
            'subject_id' => null, 'description' => null, 'estimated_minutes' => null, 'deadline_at' => null,
        ])->assertOk()->assertJsonPath('data.subject_id', null)->assertJsonPath('data.description', null)
            ->assertJsonPath('data.estimated_minutes', null)->assertJsonPath('data.deadline_at', null);
        $task->refresh();
        foreach (['subject_id', 'description', 'estimated_minutes', 'deadline_at'] as $field) {
            $this->assertNull($task->getRawOriginal($field));
        }
    }

    public function test_stale_service_model_does_not_overwrite_newer_persisted_values(): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-05 12:00:00']);
        $user = $task->user;
        Task::findOrFail($task->id)->update(['description' => 'Newer', 'priority' => 3, 'deadline_at' => '2026-11-05 12:00:00',
            'status' => TaskStatus::Completed, 'completed_at' => '2026-10-05 10:00:00']);
        $updated = app(TaskService::class)->update($user, $task, ['title' => 'Changed']);
        $this->assertSame('Changed', $updated->title);
        $this->assertSame('Newer', $updated->description);
        $this->assertSame(TaskPriority::High, $updated->priority);
        $this->assertSame('2026-11-05 12:00:00', $updated->getRawOriginal('deadline_at'));
        $this->assertSame(TaskStatus::Completed, $updated->status);
        $this->assertSame('2026-10-05 10:00:00', $updated->getRawOriginal('completed_at'));
    }

    public function test_utc_serialization_and_mutations_ignore_server_and_profile_timezones(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Los_Angeles']);
        $task = Task::factory()->for($user)->create(['deadline_at' => '2026-10-05 12:00:00',
            'status' => TaskStatus::Completed, 'completed_at' => '2026-10-05 11:00:00']);
        $originalZone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $this->actingAs($user, 'web')->getJson('/api/tasks/'.$task->id)->assertOk()
                ->assertJsonPath('data.deadline_at', '2026-10-05T12:00:00Z')->assertJsonPath('data.completed_at', '2026-10-05T11:00:00Z');
            $this->patchJson('/api/tasks/'.$task->id, ['title' => 'UTC retained'])->assertOk()
                ->assertJsonPath('data.deadline_at', '2026-10-05T12:00:00Z')->assertJsonPath('data.completed_at', '2026-10-05T11:00:00Z');
            $this->postJson('/api/tasks', ['title' => 'UTC create', 'deadline_at' => '2026-10-05T12:34:56Z'])
                ->assertCreated()->assertJsonPath('data.deadline_at', '2026-10-05T12:34:56Z');
            $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-11-05T12:34:56Z'])->assertOk()
                ->assertJsonPath('data.deadline_at', '2026-11-05T12:34:56Z');
            $this->assertSame('2026-11-05 12:34:56', $task->refresh()->getRawOriginal('deadline_at'));
        } finally {
            date_default_timezone_set($originalZone);
        }
    }

    #[DataProvider('deadlineBounds')]
    public function test_subtask_deadline_bound(?string $deadline, bool $completed, bool $deleted, bool $allowed): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-10 12:00:00']);
        $subtask = Subtask::factory()->for($task)->create([
            'deadline_at' => '2026-10-05 12:00:00', 'status' => $completed ? TaskStatus::Completed : TaskStatus::Pending,
            'completed_at' => $completed ? '2026-10-04 12:00:00' : null,
        ]);
        if ($deleted) {
            $subtask->delete();
        }
        $before = $task->refresh()->getAttributes();
        $subtaskBefore = $subtask->refresh()->getAttributes();
        $response = $this->actingAs($task->user, 'web')->patchJson('/api/tasks/'.$task->id, ['deadline_at' => $deadline, 'title' => 'New title']);
        if ($allowed) {
            $response->assertOk()->assertJsonPath('data.deadline_at', $deadline)->assertJsonPath('data.title', 'New title');
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
            $this->assertSame($before, $task->refresh()->getAttributes());
        }
        $this->assertSame($subtaskBefore, $subtask->refresh()->getAttributes());
    }

    public static function deadlineBounds(): array
    {
        return [
            'earlier pending' => ['2026-10-04T12:00:00Z', false, false, false],
            'earlier completed' => ['2026-10-04T12:00:00Z', true, false, false],
            'equal' => ['2026-10-05T12:00:00Z', false, false, true],
            'later' => ['2026-10-06T12:00:00Z', true, false, true],
            'clear' => [null, false, false, true],
            'deleted pending' => ['2026-10-04T12:00:00Z', false, true, true],
            'deleted completed' => ['2026-10-04T12:00:00Z', true, true, true],
        ];
    }

    public function test_null_subtask_deadline_does_not_block_parent_change(): void
    {
        $subtask = Subtask::factory()->create();
        $this->actingAs($subtask->task->user, 'web')->patchJson('/api/tasks/'.$subtask->task_id, ['deadline_at' => '2026-10-05T12:00:00Z'])
            ->assertOk()->assertJsonPath('data.deadline_at', '2026-10-05T12:00:00Z');
        $this->assertNull($subtask->refresh()->deadline_at);
    }

    public function test_all_active_subtasks_are_checked_including_a_later_completed_record(): void
    {
        $task = Task::factory()->create();
        Subtask::factory()->for($task)->create(['deadline_at' => '2026-10-04 12:00:00']);
        Subtask::factory()->for($task)->create(['position' => 2, 'deadline_at' => '2026-10-06 12:00:00',
            'status' => TaskStatus::Completed, 'completed_at' => '2026-10-03 12:00:00']);
        $this->actingAs($task->user, 'web')->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-05T12:00:00Z'])
            ->assertUnprocessable()->assertJsonValidationErrors('deadline_at');
        $this->assertNull($task->refresh()->deadline_at);
    }
}
