<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskProgressTest extends TestCase
{
    use RefreshDatabase;

    private function url(Task $task): string
    {
        return '/api/tasks/'.$task->id.'/progress';
    }

    #[DataProvider('progressCases')]
    public function test_counts_rounding_and_parent_state_are_independent(int $total, int $completed, ?int $percentage, bool $completedParent): void
    {
        $task = Task::factory()->create(['status' => $completedParent ? TaskStatus::Completed : TaskStatus::Pending,
            'completed_at' => $completedParent ? '2026-10-10 12:00:00' : null])->refresh();
        $before = $task->getAttributes();
        for ($i = 0; $i < $total; $i++) {
            Subtask::factory()->for($task)->create(['status' => $i < $completed ? TaskStatus::Completed : TaskStatus::Pending,
                'completed_at' => $i < $completed ? '2026-10-10 12:00:00' : null]);
        }
        $this->actingAs($task->user, 'web')->getJson($this->url($task))->assertOk()->assertExactJson(['data' => [
            'task_id' => $task->id, 'total_subtasks' => $total, 'completed_subtasks' => $completed, 'progress_percent' => $percentage,
        ]]);
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public static function progressCases(): array
    {
        return [
            'no children pending' => [0, 0, null, false], 'no children completed' => [0, 0, null, true],
            'one pending' => [1, 0, 0, false], 'one completed' => [1, 1, 100, false],
            'all pending completed parent' => [4, 0, 0, true], 'all completed pending parent' => [3, 3, 100, false],
            'quarter' => [4, 1, 25, false], 'two thirds' => [3, 2, 67, false], 'one third' => [3, 1, 33, true],
            'half-up 12.5' => [8, 1, 13, false], 'half-up 37.5' => [8, 3, 38, false], 'half-up 62.5' => [8, 5, 63, false],
            'half' => [2, 1, 50, false],
        ];
    }

    public function test_deleted_pending_and_completed_and_unrelated_children_are_excluded(): void
    {
        $task = Task::factory()->create();
        Subtask::factory()->for($task)->create();
        Subtask::factory()->for($task)->create(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00']);
        $deletedPending = Subtask::factory()->for($task)->create();
        $deletedCompleted = Subtask::factory()->for($task)->create(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00']);
        $deletedPending->delete();
        $deletedCompleted->delete();
        $otherOwned = Task::factory()->for($task->user)->create();
        Subtask::factory()->count(3)->for($otherOwned)->create(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00']);
        Subtask::factory()->create();
        $this->actingAs($task->user, 'web')->getJson($this->url($task))->assertOk()->assertExactJson(['data' => [
            'task_id' => $task->id, 'total_subtasks' => 2, 'completed_subtasks' => 1, 'progress_percent' => 50,
        ]]);
    }

    public function test_only_deleted_children_mean_undefined_progress(): void
    {
        $child = Subtask::factory()->create(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00']);
        $task = $child->task;
        $child->delete();
        $this->actingAs($task->user, 'web')->getJson($this->url($task))->assertOk()->assertJsonPath('data.total_subtasks', 0)
            ->assertJsonPath('data.completed_subtasks', 0)->assertJsonPath('data.progress_percent', null);
    }

    public function test_estimates_positions_and_sessions_do_not_define_progress(): void
    {
        $task = Task::factory()->create(['estimated_minutes' => 65535]);
        $completed = Subtask::factory()->for($task)->create(['position' => 65535, 'estimated_minutes' => 1,
            'status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00']);
        Subtask::factory()->for($task)->create(['position' => 7, 'estimated_minutes' => 65535]);
        Subtask::factory()->for($task)->create(['position' => 7, 'estimated_minutes' => null]);
        StudySession::factory()->for($task)->create(['subtask_id' => $completed->id]);
        $this->actingAs($task->user, 'web')->getJson($this->url($task))->assertOk()->assertJsonPath('data.progress_percent', 33);
        $task->update(['estimated_minutes' => null]);
        $completed->update(['estimated_minutes' => 65535, 'position' => 1]);
        $this->getJson($this->url($task))->assertOk()->assertJsonPath('data.progress_percent', 33);
    }

    public function test_child_lifecycle_refreshes_progress_while_parent_lifecycle_and_reordering_do_not(): void
    {
        $task = Task::factory()->create();
        $first = Subtask::factory()->for($task)->create(['position' => 1]);
        $second = Subtask::factory()->for($task)->create(['position' => 2]);
        $base = '/api/tasks/'.$task->id.'/subtasks';
        $this->actingAs($task->user, 'web')->getJson($this->url($task))->assertOk()->assertJsonPath('data.progress_percent', 0);
        $this->postJson($base.'/'.$first->id.'/complete')->assertOk();
        $this->getJson($this->url($task))->assertOk()->assertJsonPath('data.progress_percent', 50);
        $this->postJson($base.'/'.$second->id.'/complete')->assertOk();
        $this->getJson($this->url($task))->assertOk()->assertJsonPath('data.progress_percent', 100);
        $this->assertSame(TaskStatus::Pending, $task->refresh()->status);
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk();
        $this->getJson($this->url($task))->assertOk()->assertJsonPath('data.progress_percent', 100);
        $this->postJson($base.'/'.$first->id.'/reopen')->assertOk();
        $this->getJson($this->url($task))->assertOk()->assertJsonPath('data.progress_percent', 50);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->postJson('/api/tasks/'.$task->id.'/reopen')->assertOk();
        $this->getJson($this->url($task))->assertOk()->assertJsonPath('data.progress_percent', 50);
        $this->postJson($base.'/reorder', ['subtask_ids' => [$second->id, $first->id]])->assertOk();
        $this->getJson($this->url($task))->assertOk()->assertJsonPath('data.progress_percent', 50);
    }

    public function test_authentication_missing_deleted_and_foreign_task_security(): void
    {
        $task = Task::factory()->create();
        Subtask::factory()->for($task)->create();
        $this->getJson($this->url($task))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->getJson($this->url($task).'?user_id='.$task->user_id)->assertNotFound();
        $owned = Task::factory()->for(auth()->user())->create();
        $owned->delete();
        $this->getJson($this->url($owned))->assertNotFound();
        $this->getJson('/api/tasks/999999/progress')->assertNotFound();
    }

    public function test_get_is_read_only_and_ignores_forged_input(): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id]);
        $reminder = Reminder::factory()->for($task->user)->create(['subtask_id' => $child->id]);
        $records = [$task, $child, $session, $reminder];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web')->getJson($this->url($task).'?user_id=999999&total_subtasks=99&progress_percent=100')->assertOk()
            ->assertExactJson(['data' => ['task_id' => $task->id, 'total_subtasks' => 1, 'completed_subtasks' => 0, 'progress_percent' => 0]]);
        foreach ($records as $i => $record) {
            $this->assertSame($before[$i], $record->refresh()->getAttributes());
        }
    }

    public function test_service_reloads_owned_task_and_ignores_cached_child_collection(): void
    {
        $task = Task::factory()->create();
        $task->load('subtasks');
        Subtask::factory()->for($task)->create(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00']);
        $result = app(TaskService::class)->progress($task->user, $task);
        $this->assertSame(['task_id' => $task->id, 'total_subtasks' => 1, 'completed_subtasks' => 1, 'progress_percent' => 100], $result);
        $other = User::factory()->create();
        $task->user_id = $other->id;
        $this->expectException(ModelNotFoundException::class);
        app(TaskService::class)->progress($other, $task);
    }

    public function test_count_query_is_one_scoped_aggregate_without_collection_loading_or_writes(): void
    {
        $task = Task::factory()->create();
        Subtask::factory()->count(20)->for($task)->create();
        $this->actingAs($task->user, 'web');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->url($task))->assertOk()->assertJsonPath('data.total_subtasks', 20);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $childQueries = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'from `subtasks`')));
        $this->assertCount(1, $childQueries);
        $this->assertStringContainsString('COUNT(*)', $childQueries[0]['query']);
        $this->assertStringContainsString('SUM(CASE WHEN status = ?', $childQueries[0]['query']);
        $this->assertStringContainsString('`task_id` =', $childQueries[0]['query']);
        $this->assertStringContainsString('`subtasks`.`deleted_at` is null', $childQueries[0]['query']);
        $this->assertSame([TaskStatus::Completed->value, $task->id], $childQueries[0]['bindings']);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
        }
    }
}
