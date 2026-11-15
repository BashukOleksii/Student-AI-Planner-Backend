<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Enums\StudySessionStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningConstraintService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\PlanningWorkSelectionService;
use App\Services\Planning\StudyPlanningService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsBatchedReads;
use Tests\TestCase;

#[Group('integration')]
#[Group('mysql')]
class CompactStudyPlanningServiceTest extends TestCase
{
    use AssertsBatchedReads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T08:00:00.500000Z'));
    }

    public function test_stage_a_to_b_returns_exact_in_memory_contract_without_materializing_preferences(): void
    {
        $user = $this->user();
        $task = $this->task($user);
        $result = $this->plan($user);
        $this->assertSame(['as_of_utc' => '2026-10-12T08:00:00.500000Z', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'timezone' => 'UTC', 'sessions' => [$this->proposal($task, null, '2026-10-12T09:00:00Z', '2026-10-12T10:00:00Z')], 'work_results' => [['task_id' => $task->id, 'subtask_id' => null, 'requested_seconds' => 3600, 'allocated_seconds' => 3600, 'unscheduled_seconds' => 0, 'reason_codes' => [], 'effective_deadline_utc' => null]], 'excluded_items' => [], 'warnings' => [], 'summary' => ['requested_seconds' => 3600, 'allocated_seconds' => 3600, 'unscheduled_seconds' => 0, 'fully_allocated' => true]], $result);
        $this->assertDatabaseCount('planning_preferences', 0);
        $this->assertDatabaseCount('study_sessions', 0);
        $this->assertValidBatch($user, $result);
    }

    #[DataProvider('ineligibleCases')]
    public function test_empty_or_excluded_stage_a_work_remains_excluded(string $case): void
    {
        $user = $this->user();
        $task = null;
        if ($case !== 'no tasks') {
            $task = $this->task($user, ['estimated_minutes' => $case === 'missing estimate' ? null : 60, 'status' => $case === 'completed parent' ? TaskStatus::Completed : TaskStatus::Pending]);
        }
        if (in_array($case, ['completed parent', 'completed child'], true)) {
            $this->child($task, ['status' => $case === 'completed child' ? TaskStatus::Completed : TaskStatus::Pending]);
        } elseif ($case === 'fully reserved') {
            $this->persistedSession($user, StudySessionStatus::Planned, '2026-10-12 11:00:00', '2026-10-12 12:00:00', ['task_id' => $task->id]);
        } elseif ($case === 'fully credited') {
            $this->persistedSession($user, StudySessionStatus::Completed, '2000-01-01 10:00:00', '2000-01-01 11:00:00', ['task_id' => $task->id]);
        } elseif ($case === 'expired') {
            $task->update(['deadline_at' => '2026-10-12 07:00:00']);
        } elseif ($case === 'deleted') {
            $task->delete();
        }
        $selection = app(PlanningWorkSelectionService::class)->select($user);
        $result = $this->plan($user);
        $this->assertSame([], $result['sessions']);
        $this->assertSame([], $result['work_results']);
        $this->assertSame($selection['excluded_items'], $result['excluded_items']);
        $this->assertSame($selection['warnings'], $result['warnings']);
        $this->assertSame(['requested_seconds' => 0, 'allocated_seconds' => 0, 'unscheduled_seconds' => 0, 'fully_allocated' => true], $result['summary']);
    }

    public static function ineligibleCases(): array
    {
        return [0 => ['no tasks']];
    }

    #[DataProvider('blockerCases')]
    public function test_effective_lesson_and_session_source_policies_are_reused(string $kind, string $status, bool $deleted, string $expectedStart): void
    {
        $user = $this->user();
        $task = $this->task($user);
        if ($kind === 'lesson') {
            $lesson = Lesson::factory()->for($user)->create(['starts_at' => '2026-10-12 09:00:00', 'ends_at' => '2026-10-12 10:00:00', 'status' => $status]);
            if ($deleted) {
                $lesson->delete();
            }
        } else {
            $this->persistedSession($user, StudySessionStatus::from($status), '2026-10-12 09:00:00', '2026-10-12 10:00:00');
        }
        $result = $this->plan($user, taskId: $task->id);
        $this->assertSame($this->ts($expectedStart), $result['sessions'][0]['start']);
        $this->assertSame(3600, $result['summary']['allocated_seconds']);
        $this->assertValidBatch($user, $result);
    }

    public static function blockerCases(): array
    {
        return [0 => ['lesson', 'active', false, '2026-10-12T10:00:00Z']];
    }

    #[DataProvider('workloadStatuses')]
    public function test_completed_and_planned_scheduled_workload_is_distinct_from_stage_a_actual_credit(StudySessionStatus $status, int $allocated): void
    {
        $user = $this->user();
        $task = $this->task($user);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 90, 'max_weekly_study_minutes' => 90]);
        $this->persistedSession($user, $status, '2026-10-12 07:00:00', '2026-10-12 08:00:00', $status === StudySessionStatus::Completed ? ['actual_minutes' => 1] : []);
        $result = $this->plan($user, taskId: $task->id);
        $this->assertSame($allocated, $result['summary']['allocated_seconds']);
        $this->assertSame(3600 - $allocated, $result['summary']['unscheduled_seconds']);
        if ($allocated < 3600) {
            $this->assertContains('daily_study_limit_exceeded', $result['work_results'][0]['reason_codes']);
            $this->assertContains('weekly_study_limit_exceeded', $result['work_results'][0]['reason_codes']);
        }
        $this->assertValidBatch($user, $result);
    }

    public static function workloadStatuses(): array
    {
        return [0 => [StudySessionStatus::Planned, 1800]];
    }

    public function test_consecutive_generated_sessions_project_occupied_time_breaks_and_workload(): void
    {
        $user = $this->user();
        $first = $this->task($user, ['estimated_minutes' => 2, 'priority' => TaskPriority::High]);
        $second = $this->task($user, ['estimated_minutes' => 2]);
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 1, 'preferred_break_minutes' => 1, 'max_daily_study_minutes' => 3, 'max_weekly_study_minutes' => 3]);
        $result = $this->plan($user);
        $this->assertSame([$this->proposal($first, null, '2026-10-12T09:00:00Z', '2026-10-12T09:01:00Z'), $this->proposal($first, null, '2026-10-12T09:02:00Z', '2026-10-12T09:03:00Z'), $this->proposal($second, null, '2026-10-12T09:04:00Z', '2026-10-12T09:05:00Z')], $result['sessions']);
        $this->assertSame(180, $result['summary']['allocated_seconds']);
        $this->assertSame(60, $result['summary']['unscheduled_seconds']);
        $this->assertContains('daily_study_limit_exceeded', $result['work_results'][1]['reason_codes']);
        $this->assertContains('weekly_study_limit_exceeded', $result['work_results'][1]['reason_codes']);
        $this->assertValidBatch($user, $result);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_active_replacement_and_breaks_allow_exact_elapsed_equality(): void
    {
        $user = $this->user();
        $task = $this->task($user);
        PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 15]);
        $original = Lesson::factory()->for($user)->create(['status' => LessonStatus::Replaced]);
        Lesson::factory()->for($user)->create(['replaces_lesson_id' => $original->id, 'starts_at' => '2026-10-12 08:00:00', 'ends_at' => '2026-10-12 09:00:01']);
        $result = $this->plan($user);
        $this->assertSame($this->proposal($task, null, '2026-10-12T09:15:01Z', '2026-10-12T10:15:01Z'), $result['sessions'][0]);
        $this->assertValidBatch($user, $result);
    }

    public function test_sources_before_and_after_requested_local_dates_still_require_breaks(): void
    {
        $user = $this->user();
        $user->studyAvailabilityWindows()->delete();
        $this->window($user, 1, '00:00:00', '24:00:00');
        $task = $this->task($user, ['estimated_minutes' => 1440]);
        PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 15]);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-11 23:00:00', 'ends_at' => '2026-10-12 00:00:00']);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-13 00:00:00', 'ends_at' => '2026-10-13 01:00:00']);
        $this->travelTo(CarbonImmutable::parse('2026-10-11T12:00:00Z'));
        $result = $this->plan($user);
        $this->assertSame($this->proposal($task, null, '2026-10-12T00:15:00Z', '2026-10-12T23:45:00Z'), $result['sessions'][0]);
        $this->assertSame(1800, $result['summary']['unscheduled_seconds']);
        $this->assertValidBatch($user, $result);
    }

    public function test_short_tail_is_balanced_only_with_valid_next_placement(): void
    {
        $user = $this->user();
        $task = $this->task($user, ['estimated_minutes' => 90]);
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 40, 'max_session_minutes' => 60, 'preferred_break_minutes' => 10]);
        $result = $this->plan($user);
        $this->assertSame([$this->proposal($task, null, '2026-10-12T09:00:00Z', '2026-10-12T09:50:00Z'), $this->proposal($task, null, '2026-10-12T10:00:00Z', '2026-10-12T10:40:00Z')], $result['sessions']);
        $this->assertTrue($result['summary']['fully_allocated']);
        $this->assertValidBatch($user, $result);
    }

    private function user(string $timezone = 'UTC', bool $allDays = false): User
    {
        $user = User::factory()->create(['timezone' => $timezone]);
        foreach ($allDays ? range(1, 7) : [1] as $day) {
            $this->window($user, $day, '09:00:00', '12:00:00');
        }

        return $user;
    }

    private function window(User $user, int $day, string $start, string $end): void
    {
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => $start, 'ends_at' => $end]);
    }

    private function task(User $user, array $values = []): Task
    {
        return Task::factory()->for($user)->create(['estimated_minutes' => 60, 'completed_at' => ($values['status'] ?? null) === TaskStatus::Completed ? '2026-10-11 12:00:00' : null, ...$values]);
    }

    private function child(Task $task, array $values = []): Subtask
    {
        return Subtask::factory()->for($task)->create(['estimated_minutes' => 60, 'completed_at' => ($values['status'] ?? null) === TaskStatus::Completed ? '2026-10-11 12:00:00' : null, ...$values]);
    }

    private function persistedSession(User $user, StudySessionStatus $status, string $start, string $end, array $values = []): StudySession
    {
        return StudySession::factory()->create(['user_id' => $user->id, 'task_id' => $values['task_id'] ?? Task::factory()->for($user)->create()->id, 'status' => $status, 'starts_at' => $start, 'ends_at' => $end, 'completed_at' => $status === StudySessionStatus::Completed ? $end : null, ...$values]);
    }

    private function plan(User $user, string $start = '2026-10-12', string $end = '2026-10-12', mixed $taskId = null, mixed $subtaskId = null): array
    {
        return app(StudyPlanningService::class)->plan($user, $start, $end, $taskId, $subtaskId);
    }

    private function proposal(Task $task, ?Subtask $child, string $start, string $end): array
    {
        $start = $this->ts($start);
        $end = $this->ts($end);

        return ['task_id' => $task->id, 'subtask_id' => $child?->id, 'start' => $start, 'end' => $end, 'duration_seconds' => $end - $start];
    }

    private function ts(string $value): int
    {
        return (new DateTimeImmutable($value))->getTimestamp();
    }

    private function assertValidBatch(User $user, array $result): void
    {
        $context = app(PlanningContextService::class)->load($user, $result['start_date'], $result['end_date']);
        $work = app(PlanningWorkSelectionService::class)->select($user)['work_items'];
        $deadlines = [];
        foreach ($work as $item) {
            $deadlines[$item['task_id'].':'.($item['subtask_id'] ?? 'task')] = $item['effective_deadline_utc'];
        }
        foreach ($result['sessions'] as $index => $session) {
            $others = [];
            foreach ($result['sessions'] as $key => $other) {
                if ($key !== $index) {
                    $others[] = ['start' => $other['start'], 'end' => $other['end']];
                }
            }
            $candidate = ['start' => $session['start'], 'end' => $session['end']];
            $this->assertSame([], app(PlanningConstraintService::class)->evaluate($candidate, $context['free_intervals'], [...$context['break_anchors'], ...$others], [...$context['workload_sessions'], ...$others], $context['timezone'], $context['preferences'], $deadlines[$session['task_id'].':'.($session['subtask_id'] ?? 'task')]));
            $this->assertGreaterThan(0, $session['duration_seconds']);
            $this->assertSame($session['duration_seconds'], $session['end'] - $session['start']);
            $this->assertGreaterThanOrEqual(new DateTimeImmutable($result['as_of_utc']), new DateTimeImmutable('@'.$session['start']));
        }
        foreach ($result['work_results'] as $item) {
            $this->assertSame($item['requested_seconds'], $item['allocated_seconds'] + $item['unscheduled_seconds']);
        }
    }
}
