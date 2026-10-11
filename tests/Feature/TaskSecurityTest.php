<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_endpoints_require_authentication(): void
    {
        $task = Task::factory()->create();
        $this->postJson('/api/tasks', ['title' => 'Test'])->assertUnauthorized();
        $this->getJson('/api/tasks/'.$task->id)->assertUnauthorized();
        $this->patchJson('/api/tasks/'.$task->id, [])->assertUnauthorized();
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_foreign_task_is_hidden_before_body_validation(): void
    {
        $task = Task::factory()->create()->refresh();
        $before = $task->getAttributes();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/tasks/'.$task->id)->assertNotFound();
        $this->patchJson('/api/tasks/'.$task->id, ['title' => null, 'deadline_at' => 'invalid', 'user_id' => $task->user_id])->assertNotFound();
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public function test_missing_and_soft_deleted_tasks_are_not_bound(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web');
        $task->delete();
        foreach ([$task->id, 999999] as $id) {
            $this->getJson('/api/tasks/'.$id)->assertNotFound();
            $this->patchJson('/api/tasks/'.$id, ['title' => null])->assertNotFound();
        }
        $this->assertSoftDeleted($task);
    }

    public function test_foreign_and_missing_subject_return_identical_validation_without_writes(): void
    {
        $task = Task::factory()->create()->refresh();
        $foreign = Subject::factory()->create();
        $before = $task->getAttributes();
        $foreignBefore = $foreign->refresh()->getAttributes();
        $this->actingAs($task->user, 'web');
        $errors = [];
        foreach ([$foreign->id, 999999] as $id) {
            $create = $this->postJson('/api/tasks', ['title' => 'Test', 'subject_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('subject_id');
            $update = $this->patchJson('/api/tasks/'.$task->id, ['title' => 'Changed', 'subject_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('subject_id');
            $this->assertSame($create->json(), $update->json());
            $errors[] = $create->json();
        }
        $this->assertSame($errors[0], $errors[1]);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame($foreignBefore, $foreign->refresh()->getAttributes());
        $this->assertDatabaseCount('tasks', 1);
    }

    #[DataProvider('repairs')]
    public function test_legacy_foreign_subject_is_masked_and_must_be_explicitly_repaired(bool $detach): void
    {
        $subject = Subject::factory()->create(['name' => 'Private foreign subject']);
        $task = Task::factory()->create(['subject_id' => $subject->id])->refresh();
        $before = $task->getAttributes();
        $subjectBefore = $subject->refresh()->getAttributes();
        $this->actingAs($task->user, 'web')->getJson('/api/tasks/'.$task->id)->assertOk()->assertJsonPath('data.subject_id', null)
            ->assertJsonMissing(['name' => 'Private foreign subject']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->patchJson('/api/tasks/'.$task->id, ['title' => 'Unrelated'])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->assertSame($before, $task->refresh()->getAttributes());
        $owned = Subject::factory()->for($task->user)->create();
        $id = $detach ? null : $owned->id;
        $this->patchJson('/api/tasks/'.$task->id, ['subject_id' => $id])->assertOk()->assertJsonPath('data.subject_id', $id);
        $this->patchJson('/api/tasks/'.$task->id, ['title' => 'Repaired'])->assertOk();
        $this->assertSame($id, $task->refresh()->subject_id);
        $this->assertSame($subjectBefore, $subject->refresh()->getAttributes());
    }

    public static function repairs(): array
    {
        return ['detach' => [true], 'owned subject' => [false]];
    }

    public function test_forged_internal_fields_cannot_change_create_or_patch_ownership_or_lifecycle(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $forged = ['id' => 999999, 'user_id' => $foreign->id, 'status' => 'completed', 'completed_at' => '2026-10-05T12:00:00Z',
            'created_at' => '2000-01-01', 'updated_at' => '2000-01-01', 'deleted_at' => '2000-01-01', 'subtasks' => [['title' => 'Injected']]];
        $this->actingAs($user, 'web')->postJson('/api/tasks', ['title' => 'Trusted', ...$forged])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.completed_at', null);
        $task = Task::sole();
        $this->assertNotSame(999999, $task->id);
        $this->assertSame($user->id, $task->user_id);
        $this->assertNull($task->deleted_at);
        $this->assertNotSame('2000-01-01', $task->getRawOriginal('created_at'));
        $before = $task->getAttributes();
        $this->patchJson('/api/tasks/'.$task->id, $forged)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.completed_at', null);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $task->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-05 12:00:00']);
        $before = $task->refresh()->getAttributes();
        $this->patchJson('/api/tasks/'.$task->id, ['status' => 'pending', 'completed_at' => null, 'user_id' => $foreign->id])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-05T12:00:00Z');
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertDatabaseCount('subtasks', 0);
    }

    public function test_service_scopes_reload_even_if_input_model_owner_is_forged(): void
    {
        $task = Task::factory()->create()->refresh();
        $before = $task->getAttributes();
        $other = User::factory()->create();
        $task->user_id = $other->id;
        try {
            app(TaskService::class)->update($other, $task, ['title' => 'Stolen']);
            $this->fail('The owner-scoped reload must reject this task.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $task->refresh()->getAttributes());
        }
    }
}
