<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskMissedStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_miss_is_an_explicit_idempotent_task_only_transition_and_patch_cannot_bypass_it(): void
    {
        $task = Task::factory()->create(['title' => 'Prepare report', 'estimated_minutes' => 90]);
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-11-01 10:00:00']);
        $session = StudySession::factory()->for($task)->create(['status' => 'missed']);
        $before = $task->fresh()->getAttributes();
        $childBefore = $child->fresh()->getAttributes();
        $sessionBefore = $session->fresh()->getAttributes();
        $this->actingAs($task->user, 'web')->postJson('/api/tasks/'.$task->id.'/miss', ['status' => 'completed', 'title' => 'Ignored'])
            ->assertOk()->assertJsonPath('data.status', 'missed')->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.title', 'Prepare report');
        $this->assertSame(Arr::except($before, ['status', 'updated_at']), Arr::except($task->fresh()->getAttributes(), ['status', 'updated_at']));
        $this->assertSame($childBefore, $child->fresh()->getAttributes());
        $this->assertSame($sessionBefore, $session->fresh()->getAttributes());
        $this->assertDatabaseCount('study_sessions', 1);
        $this->getJson('/api/tasks?status=missed')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $task->id);
        $this->patchJson('/api/tasks/'.$task->id, ['status' => 'pending', 'completed_at' => '2026-11-08T10:00:00Z'])
            ->assertOk()->assertJsonPath('data.status', 'missed')->assertJsonPath('data.completed_at', null);
        $this->assertSame('/api/tasks/'.$task->id.'/miss', route('tasks.miss', $task, false));
    }

    #[DataProvider('unfinishedTransitions')]
    public function test_missed_task_can_reopen_or_complete_without_changing_subtasks(string $operation, string $expected): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08T10:20:30.123456Z'));
        $task = Task::factory()->create(['status' => 'missed']);
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-11-01 10:00:00']);
        $before = $child->fresh()->getAttributes();
        $this->actingAs($task->user, 'web')->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertOk()
            ->assertJsonPath('data.status', $expected)
            ->assertJsonPath('data.completed_at', $expected === 'completed' ? '2026-11-08T10:20:30Z' : null);
        $this->assertSame($before, $child->fresh()->getAttributes());
    }

    public static function unfinishedTransitions(): array
    {
        return [['reopen', 'pending'], ['complete', 'completed']];
    }

    public function test_stale_model_cannot_mark_a_concurrently_completed_task_missed(): void
    {
        $task = Task::factory()->create();
        app(TaskService::class)->complete($task->user, $task);
        $before = $task->fresh()->getAttributes();
        config(['app.debug' => true]);
        $this->actingAs($task->user, 'web')->postJson('/api/tasks/'.$task->id.'/miss')->assertConflict()
            ->assertExactJson(['message' => 'A completed task must be reopened before it can be marked missed.']);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }
}
