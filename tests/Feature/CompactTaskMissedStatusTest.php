<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Planning\StudyPlanningService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactTaskMissedStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_miss_preserves_deadline_and_remaining_plan_after_credit_and_reservations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13T08:00:00Z'));
        $task = Task::factory()->create(['estimated_minutes' => 90, 'deadline_at' => '2026-11-13 17:00:00']);
        $owner = $task->user;
        $owner->update(['timezone' => 'UTC']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 5, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        StudySession::factory()->for($task)->create(['status' => 'completed', 'starts_at' => '2026-11-13 07:00:00', 'ends_at' => '2026-11-13 08:00:00', 'completed_at' => '2026-11-13 08:00:00', 'actual_minutes' => 15]);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 11:30:00']);
        $planner = app(StudyPlanningService::class);
        $before = $planner->plan($owner, '2026-11-13', '2026-11-13', $task->id);
        $this->assertSame(2700, $before['summary']['requested_seconds']);
        $this->assertSame(2700, $before['summary']['allocated_seconds']);
        $this->actingAs($owner)->postJson('/api/tasks/'.$task->id.'/miss')->assertOk()->assertJsonPath('data.status', 'missed');
        $this->assertSame($before, $planner->plan($owner, '2026-11-13', '2026-11-13', $task->id));
        $this->assertSame('2026-11-13 17:00:00', $task->fresh()->getRawOriginal('deadline_at'));
        $this->assertDatabaseCount('study_sessions', 2);
    }

    #[DataProvider('unfinishedTransitions')]
    public function test_missed_task_can_reopen_or_complete_without_changing_subtasks(string $operation, string $expected): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08T10:20:30.123456Z'));
        $task = Task::factory()->create(['status' => 'missed']);
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-11-01 10:00:00']);
        $before = $child->fresh()->getAttributes();
        $this->actingAs($task->user, 'web')->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertOk()->assertJsonPath('data.status', $expected)->assertJsonPath('data.completed_at', $expected === 'completed' ? '2026-11-08T10:20:30Z' : null);
        $this->assertSame($before, $child->fresh()->getAttributes());
    }

    public static function unfinishedTransitions(): array
    {
        return [0 => ['reopen', 'pending'], 1 => ['complete', 'completed']];
    }

    public function test_stale_model_cannot_mark_a_concurrently_completed_task_missed(): void
    {
        $task = Task::factory()->create();
        app(TaskService::class)->complete($task->user, $task);
        $before = $task->fresh()->getAttributes();
        config(['app.debug' => true]);
        $this->actingAs($task->user, 'web')->postJson('/api/tasks/'.$task->id.'/miss')->assertConflict()->assertExactJson(['message' => 'Спочатку поновіть виконане завдання, щоб позначити його незавершеним.']);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }
}
