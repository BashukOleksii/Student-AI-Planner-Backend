<?php

namespace Tests\Feature;

use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\Actions\PlanningActionArgumentValidator;
use App\Services\Planning\PlanningPreferenceService;
use App\Services\Planning\RecoverySuggestionService;
use App\Services\Planning\StudyAvailabilityService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Schedule\LessonService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use InvalidArgumentException;
use Tests\TestCase;

class PlanningCompletionRulesTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_missing_actual_is_rejected_by_http_service_and_ai_without_changes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13T08:00:00Z'));
        $owner = User::factory()->create();
        $task = Task::factory()->for($owner)->create();
        $session = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-12 08:00:00', 'ends_at' => '2026-11-12 09:00:00']);
        $this->actingAs($owner)->postJson('/api/study-sessions/'.$session->id.'/complete', [])
            ->assertUnprocessable()->assertJsonValidationErrors('actual_minutes');
        foreach (['service', 'ai'] as $path) {
            try {
                $path === 'service' ? app(StudySessionLifecycleService::class)->complete($owner, (string) $session->id)
                    : app(PlanningActionArgumentValidator::class)->validate('propose_session_complete', ['study_session_id' => $session->id]);
                $this->fail('Missing reported duration was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame('planned', $session->fresh()->status->value);
            }
        }
        $this->assertNull($session->fresh()->actual_minutes);
    }

    public function test_effective_deadline_is_exposed_without_changing_expired_work(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13T08:00:00Z'));
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 60, 'deadline_at' => '2026-11-16 17:00:00']);
        Subtask::factory()->for($task)->create(['estimated_minutes' => 60, 'deadline_at' => '2026-11-12 17:00:00']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1]);
        $this->actingAs($owner)->postJson('/api/planning/study-plans/preview', ['task_id' => $task->id,
            'start_date' => '2026-11-13', 'end_date' => '2026-11-19'])->assertOk()
            ->assertJsonFragment(['effective_deadline_at' => '2026-11-12T17:00:00Z'])
            ->assertJsonFragment(['reason_codes' => ['deadline_expired']]);
        $this->assertSame('2026-11-16 17:00:00', $task->fresh()->getRawOriginal('deadline_at'));
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_subtask_api_exposes_parent_or_earlier_child_deadline(): void
    {
        $owner = User::factory()->create();
        $task = Task::factory()->for($owner)->create(['deadline_at' => '2026-11-16 17:00:00']);
        $inherited = Subtask::factory()->for($task)->create(['deadline_at' => null]);
        $earlier = Subtask::factory()->for($task)->create(['deadline_at' => '2026-11-15 17:00:00']);
        $this->actingAs($owner)->getJson('/api/tasks/'.$task->id.'/subtasks/'.$inherited->id)->assertOk()
            ->assertJsonPath('data.deadline_at', null)->assertJsonPath('data.effective_deadline_at', '2026-11-16T17:00:00Z');
        $this->getJson('/api/tasks/'.$task->id.'/subtasks/'.$earlier->id)->assertOk()
            ->assertJsonPath('data.effective_deadline_at', '2026-11-15T17:00:00Z');
    }

    public function test_existing_detector_reacts_to_conditions_without_mutating_sessions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16T08:00:00Z'));
        foreach (['availability', 'preferences', 'deadline', 'lesson'] as $change) {
            $owner = User::factory()->create(['timezone' => 'UTC']);
            $task = Task::factory()->for($owner)->create(['estimated_minutes' => 60, 'deadline_at' => '2026-11-18 18:00:00']);
            $window = StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
            $session = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-16 09:00:00', 'ends_at' => '2026-11-16 10:00:00']);
            $before = $session->fresh()->getAttributes();
            if ($change === 'availability') {
                app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '11:00:00']);
            } elseif ($change === 'preferences') {
                app(PlanningPreferenceService::class)->replace($owner, ['min_session_minutes' => 90, 'max_session_minutes' => 120, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null]);
            } elseif ($change === 'deadline') {
                app(TaskService::class)->update($owner, $task, ['deadline_at' => '2026-11-16T09:30:00Z']);
            } else {
                $subject = Subject::factory()->for($owner)->create();
                app(LessonService::class)->create($owner, ['subject_id' => $subject->id, 'type' => 'lecture', 'starts_at' => '2026-11-16T09:00:00Z', 'ends_at' => '2026-11-16T10:00:00Z']);
            }
            $this->assertSame('created', app(RecoverySuggestionService::class)->detect($owner->id));
            $this->assertSame('replan', $owner->recoverySuggestions()->where('status', 'pending')->sole()->kind, $change);
            $this->assertSame($before, $session->fresh()->getAttributes());
        }
    }
}
