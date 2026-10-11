<?php

namespace Tests\Feature;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\SubtaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubtaskSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function base(Task $task): string
    {
        return '/api/tasks/'.$task->id.'/subtasks';
    }

    public function test_all_basic_endpoints_require_authentication(): void
    {
        $subtask = Subtask::factory()->create();
        $base = $this->base($subtask->task);
        $this->getJson($base)->assertUnauthorized();
        $this->postJson($base, ['title' => 'Test'])->assertUnauthorized();
        $this->getJson($base.'/'.$subtask->id)->assertUnauthorized();
        $this->patchJson($base.'/'.$subtask->id, [])->assertUnauthorized();
    }

    public function test_foreign_parent_is_hidden_before_body_validation_and_no_rows_change(): void
    {
        $subtask = Subtask::factory()->create()->refresh();
        $task = $subtask->task;
        $before = $subtask->getAttributes();
        $this->actingAs(User::factory()->create(), 'web');
        $base = $this->base($task);
        $this->getJson($base)->assertNotFound();
        $this->postJson($base, ['title' => null])->assertNotFound();
        $this->getJson($base.'/'.$subtask->id)->assertNotFound();
        $this->patchJson($base.'/'.$subtask->id, ['title' => null, 'deadline_at' => 'invalid'])->assertNotFound();
        $this->assertSame($before, $subtask->refresh()->getAttributes());
        $this->assertDatabaseCount('subtasks', 1);
    }

    public function test_owned_parent_with_other_owned_or_foreign_child_is_hidden(): void
    {
        $task = Task::factory()->create();
        $sameUser = Task::factory()->for($task->user)->create();
        $ownedChild = Subtask::factory()->for($sameUser)->create()->refresh();
        $foreignChild = Subtask::factory()->create()->refresh();
        $this->actingAs($task->user, 'web');
        foreach ([$ownedChild, $foreignChild] as $child) {
            $before = $child->getAttributes();
            $this->getJson($this->base($task).'/'.$child->id)->assertNotFound();
            $this->patchJson($this->base($task).'/'.$child->id, ['title' => null, 'task_id' => $task->id])->assertNotFound();
            $this->assertSame($before, $child->refresh()->getAttributes());
        }
    }

    public function test_missing_and_deleted_parents_and_children_are_excluded(): void
    {
        $child = Subtask::factory()->create();
        $task = $child->task;
        $this->actingAs($task->user, 'web');
        $child->delete();
        foreach ([$child->id, 999999] as $id) {
            $this->getJson($this->base($task).'/'.$id)->assertNotFound();
            $this->patchJson($this->base($task).'/'.$id, ['title' => null])->assertNotFound();
        }
        $live = Subtask::factory()->for($task)->create();
        $task->delete();
        foreach ([$task->id, 999999] as $id) {
            $base = '/api/tasks/'.$id.'/subtasks';
            $this->getJson($base)->assertNotFound();
            $this->postJson($base, ['title' => null])->assertNotFound();
            $this->getJson($base.'/'.$live->id)->assertNotFound();
            $this->patchJson($base.'/'.$live->id, [])->assertNotFound();
        }
        $this->assertNotSoftDeleted($live);
    }

    #[DataProvider('directCases')]
    public function test_direct_service_reloads_prevent_forged_or_mismatched_models(string $operation, string $scenario): void
    {
        $child = Subtask::factory()->create()->refresh();
        $task = $child->task;
        $user = $task->user;
        $before = $child->getAttributes();
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
            $before = $child->refresh()->getAttributes();
        }
        $service = app(SubtaskService::class);
        try {
            match ($operation) {
                'all' => $service->all($user, $task),
                'find' => $service->find($user, $task, $child),
                'create' => $service->create($user, $task, ['title' => 'Forbidden']),
                'update' => $service->update($user, $task, $child, ['title' => 'Forbidden']),
            };
            $this->fail('Scoped reloading must reject this context.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $child->refresh()->getAttributes());
        }
    }

    public static function directCases(): array
    {
        $cases = [];
        foreach (['all', 'find', 'create', 'update'] as $operation) {
            foreach (['foreign', 'deleted parent'] as $scenario) {
                $cases[] = [$operation, $scenario];
            }
        }
        foreach (['find', 'update'] as $operation) {
            foreach (['mismatch', 'deleted child'] as $scenario) {
                $cases[] = [$operation, $scenario];
            }
        }

        return $cases;
    }
}
