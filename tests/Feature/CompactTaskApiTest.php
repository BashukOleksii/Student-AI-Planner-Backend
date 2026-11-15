<?php

namespace Tests\Feature;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactTaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_minimal_create_defaults_and_public_shape(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user, 'web')->postJson('/api/tasks', ['title' => 'Курсова робота'])->assertCreated();
        $task = Task::sole();
        $response->assertExactJson(['data' => ['id' => $task->id, 'subject_id' => null, 'title' => 'Курсова робота', 'description' => null, 'status' => 'pending', 'priority' => 2, 'estimated_minutes' => null, 'deadline_at' => null, 'completed_at' => null]]);
        $this->assertSame($user->id, $task->user_id);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame(TaskPriority::Normal, $task->priority);
        $this->getJson('/api/tasks/'.$task->id)->assertOk()->assertExactJson($response->json());
    }

    public function test_owned_subject_and_all_optional_fields(): void
    {
        $subject = Subject::factory()->create();
        $this->actingAs($subject->user, 'web')->postJson('/api/tasks', ['title' => str_repeat('Я', 255), 'subject_id' => $subject->id, 'description' => 'Підготувати звіт', 'estimated_minutes' => 45, 'deadline_at' => '2020-02-29T12:34:56Z', 'priority' => 3])->assertCreated()->assertJsonPath('data.subject_id', $subject->id)->assertJsonPath('data.priority', 3)->assertJsonPath('data.description', 'Підготувати звіт')->assertJsonPath('data.deadline_at', '2020-02-29T12:34:56Z');
        $this->assertDatabaseHas('tasks', ['user_id' => $subject->user_id, 'deadline_at' => '2020-02-29 12:34:56', 'estimated_minutes' => 45]);
    }

    public function test_explicit_nullable_fields_can_be_null_on_create(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/tasks', ['title' => 'Test', 'subject_id' => null, 'description' => null, 'estimated_minutes' => null, 'deadline_at' => null])->assertCreated()->assertJsonPath('data.subject_id', null)->assertJsonPath('data.description', null)->assertJsonPath('data.estimated_minutes', null)->assertJsonPath('data.deadline_at', null);
    }

    #[DataProvider('approvedNumbers')]
    public function test_approved_priority_and_estimate_bounds(string $field, int $value): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/tasks', ['title' => 'Test', $field => $value])->assertCreated()->assertJsonPath('data.'.$field, $value);
        $this->patchJson('/api/tasks/'.Task::sole()->id, [$field => $value])->assertOk()->assertJsonPath('data.'.$field, $value);
    }

    public static function approvedNumbers(): array
    {
        return [0 => ['priority', 1]];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_has_no_create_or_partial_patch_mutation(string $field, mixed $value): void
    {
        $task = Task::factory()->create()->refresh();
        $before = $task->getAttributes();
        $this->actingAs($task->user, 'web');
        $this->postJson('/api/tasks', ['title' => 'Test', $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/tasks/'.$task->id, ['description' => 'Must roll back', $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public static function invalidInput(): array
    {
        return ['title 0' => ['title', null]];
    }

    public function test_title_is_required_on_create(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/tasks', [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('tasks', 0);
    }
}
