<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Enums\StudySessionStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningCandidateEvaluationService;
use App\Services\Planning\PlanningConstraintService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\PlanningWorkSelectionService;
use App\Services\Planning\StudyPlanningService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class StudyPlanningServiceTest extends TestCase
{
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
        $this->assertSame(['as_of_utc' => '2026-10-12T08:00:00.500000Z', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'timezone' => 'UTC',
            'sessions' => [$this->proposal($task, null, '2026-10-12T09:00:00Z', '2026-10-12T10:00:00Z')],
            'work_results' => [['task_id' => $task->id, 'subtask_id' => null, 'requested_seconds' => 3600, 'allocated_seconds' => 3600, 'unscheduled_seconds' => 0, 'reason_codes' => []]],
            'excluded_items' => [], 'warnings' => [], 'summary' => ['requested_seconds' => 3600, 'allocated_seconds' => 3600, 'unscheduled_seconds' => 0, 'fully_allocated' => true]], $result);
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
            $task = $this->task($user, ['estimated_minutes' => $case === 'missing estimate' ? null : 60,
                'status' => $case === 'completed parent' ? TaskStatus::Completed : TaskStatus::Pending]);
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
        return array_map(fn ($case): array => [$case], ['no tasks', 'missing estimate', 'completed parent', 'completed child', 'fully reserved', 'fully credited', 'expired', 'deleted']);
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
        $cases = [
            ['lesson', 'active', false, '2026-10-12T10:00:00Z'], ['lesson', 'cancelled', false, '2026-10-12T09:00:00Z'],
            ['lesson', 'replaced', false, '2026-10-12T09:00:00Z'], ['lesson', 'active', true, '2026-10-12T09:00:00Z'],
        ];
        foreach (StudySessionStatus::cases() as $status) {
            $cases[] = ['session', $status->value, false, $status === StudySessionStatus::Planned ? '2026-10-12T10:00:00Z' : '2026-10-12T09:00:00Z'];
        }

        return $cases;
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
        return array_map(fn ($status): array => [$status, in_array($status, [StudySessionStatus::Planned, StudySessionStatus::Completed], true) ? 1800 : 3600], StudySessionStatus::cases());
    }

    public function test_consecutive_generated_sessions_project_occupied_time_breaks_and_workload(): void
    {
        $user = $this->user();
        $first = $this->task($user, ['estimated_minutes' => 2, 'priority' => TaskPriority::High]);
        $second = $this->task($user, ['estimated_minutes' => 2]);
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 1, 'preferred_break_minutes' => 1, 'max_daily_study_minutes' => 3, 'max_weekly_study_minutes' => 3]);
        $result = $this->plan($user);
        $this->assertSame([
            $this->proposal($first, null, '2026-10-12T09:00:00Z', '2026-10-12T09:01:00Z'),
            $this->proposal($first, null, '2026-10-12T09:02:00Z', '2026-10-12T09:03:00Z'),
            $this->proposal($second, null, '2026-10-12T09:04:00Z', '2026-10-12T09:05:00Z'),
        ], $result['sessions']);
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
        $this->assertSame([
            $this->proposal($task, null, '2026-10-12T09:00:00Z', '2026-10-12T09:50:00Z'),
            $this->proposal($task, null, '2026-10-12T10:00:00Z', '2026-10-12T10:40:00Z'),
        ], $result['sessions']);
        $this->assertTrue($result['summary']['fully_allocated']);
        $this->assertValidBatch($user, $result);
    }

    public function test_second_precision_credit_and_subminimum_tail_are_preserved(): void
    {
        $user = $this->user();
        $task = $this->task($user, ['estimated_minutes' => 2]);
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 1, 'max_session_minutes' => 1]);
        $this->persistedSession($user, StudySessionStatus::Completed, '2000-01-01 09:00:00', '2000-01-01 09:00:01', ['task_id' => $task->id]);
        $result = $this->plan($user);
        $this->assertSame(119, $result['summary']['requested_seconds']);
        $this->assertSame(60, $result['summary']['allocated_seconds']);
        $this->assertSame(59, $result['summary']['unscheduled_seconds']);
        $this->assertContains('below_minimum_session_duration', $result['work_results'][0]['reason_codes']);
        $this->assertValidBatch($user, $result);
    }

    public function test_no_whole_minute_default_when_preferences_are_null(): void
    {
        $user = $this->user();
        $task = $this->task($user, ['estimated_minutes' => 1]);
        PlanningPreference::factory()->for($user)->create();
        $this->persistedSession($user, StudySessionStatus::Completed, '2000-01-01 09:00:00', '2000-01-01 09:00:59', ['task_id' => $task->id]);
        $result = $this->plan($user);
        $this->assertSame([$this->proposal($task, null, '2026-10-12T09:00:00Z', '2026-10-12T09:00:01Z')], $result['sessions']);
        $this->assertValidBatch($user, $result);
    }

    public function test_priority_child_deadlines_and_positions_follow_stage_a_order(): void
    {
        $user = $this->user();
        $noDeadline = $this->task($user, ['estimated_minutes' => 30, 'priority' => TaskPriority::High]);
        $normal = $this->task($user, ['estimated_minutes' => 30, 'deadline_at' => '2026-10-12 12:00:00']);
        $high = $this->task($user, ['priority' => TaskPriority::High, 'deadline_at' => '2026-10-12 12:00:00']);
        $laterPosition = $this->child($high, ['estimated_minutes' => 30, 'position' => 2]);
        $firstPosition = $this->child($high, ['estimated_minutes' => 30, 'position' => 1]);
        $earlier = $this->child($noDeadline, ['estimated_minutes' => 30, 'deadline_at' => '2026-10-12 09:30:00']);
        $result = $this->plan($user);
        $this->assertSame([
            $this->proposal($noDeadline, $earlier, '2026-10-12T09:00:00Z', '2026-10-12T09:30:00Z'),
            $this->proposal($high, $firstPosition, '2026-10-12T09:30:00Z', '2026-10-12T10:00:00Z'),
            $this->proposal($high, $laterPosition, '2026-10-12T10:00:00Z', '2026-10-12T10:30:00Z'),
            $this->proposal($normal, null, '2026-10-12T10:30:00Z', '2026-10-12T11:00:00Z'),
        ], $result['sessions']);
        $this->assertValidBatch($user, $result);
    }

    #[DataProvider('deadlineCases')]
    public function test_effective_task_child_deadline_protection(?string $parent, ?string $child, string $expectedEnd): void
    {
        $user = $this->user();
        $task = $this->task($user, ['deadline_at' => $parent]);
        $subtask = $this->child($task, ['deadline_at' => $child]);
        $result = $this->plan($user, taskId: $task->id, subtaskId: $subtask->id);
        $this->assertSame($this->ts($expectedEnd), $result['sessions'][0]['end']);
        $this->assertSame([], $result['excluded_items']);
        $this->assertValidBatch($user, $result);
    }

    public static function deadlineCases(): array
    {
        return [
            ['2026-10-12 12:00:00', '2026-10-12 09:30:00', '2026-10-12T09:30:00Z'],
            ['2026-10-12 09:30:00', '2026-10-12 12:00:00', '2026-10-12T09:30:00Z'],
            ['2026-10-12 10:00:00', null, '2026-10-12T10:00:00Z'],
            [null, '2026-10-12 10:00:00', '2026-10-12T10:00:00Z'],
            [null, null, '2026-10-12T10:00:00Z'],
        ];
    }

    public function test_no_availability_blocked_capacity_and_deadline_clipping_report_exact_partial_results(): void
    {
        $user = $this->user();
        $task = $this->task($user, ['deadline_at' => '2026-10-12 09:30:00']);
        $partial = $this->plan($user);
        $this->assertSame(1800, $partial['summary']['allocated_seconds']);
        $this->assertSame(1800, $partial['summary']['unscheduled_seconds']);
        $this->assertContains('deadline_capacity_exhausted', $partial['work_results'][0]['reason_codes']);
        $user->studyAvailabilityWindows()->delete();
        $empty = $this->plan($user);
        $this->assertSame([], $empty['sessions']);
        $this->assertSame(3600, $empty['summary']['unscheduled_seconds']);
        $this->assertSame(['no_available_study_time', 'planning_horizon_exhausted'], $empty['work_results'][0]['reason_codes']);
        $this->window($user, 1, '09:00:00', '12:00:00');
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-12 08:00:00', 'ends_at' => '2026-10-12 13:00:00']);
        $this->assertSame([], $this->plan($user)['sessions']);
    }

    public function test_later_start_inside_one_free_interval_can_satisfy_minimum_across_midnight(): void
    {
        $user = $this->user();
        $user->studyAvailabilityWindows()->delete();
        $this->window($user, 1, '23:00:00', '24:00:00');
        $this->window($user, 2, '00:00:00', '01:00:00');
        $task = $this->task($user, ['estimated_minutes' => 30]);
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 30, 'max_daily_study_minutes' => 60]);
        $this->persistedSession($user, StudySessionStatus::Completed, '2026-10-12 07:00:00', '2026-10-12 07:50:00', ['actual_minutes' => 1]);
        $result = $this->plan($user, '2026-10-12', '2026-10-13', $task->id);
        $this->assertSame([$this->proposal($task, null, '2026-10-12T23:50:00Z', '2026-10-13T00:20:00Z')], $result['sessions']);
        $this->assertValidBatch($user, $result);
    }

    public function test_iso_week_year_workload_includes_completed_sessions_before_requested_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31T08:00:00Z'));
        $user = $this->user('UTC', allDays: true);
        $task = $this->task($user);
        PlanningPreference::factory()->for($user)->create(['max_weekly_study_minutes' => 90]);
        $this->persistedSession($user, StudySessionStatus::Completed, '2026-12-28 08:00:00', '2026-12-28 09:00:00', ['actual_minutes' => 1]);
        $result = $this->plan($user, '2027-01-01', '2027-01-01', $task->id);
        $this->assertSame(1800, $result['summary']['allocated_seconds']);
        $this->assertContains('weekly_study_limit_exceeded', $result['work_results'][0]['reason_codes']);
        $this->assertValidBatch($user, $result);
    }

    public function test_generated_workload_resets_at_local_day_and_iso_week_boundaries_with_breaks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11T20:00:00Z'));
        $user = $this->user();
        $user->studyAvailabilityWindows()->delete();
        $this->window($user, 7, '23:57:00', '24:00:00');
        $this->window($user, 1, '00:00:00', '00:03:00');
        $first = $this->task($user, ['estimated_minutes' => 3, 'priority' => TaskPriority::High]);
        $second = $this->task($user, ['estimated_minutes' => 1]);
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 1, 'preferred_break_minutes' => 1,
            'max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => 1]);
        $result = $this->plan($user, '2026-10-11', '2026-10-12');
        $this->assertSame([
            $this->proposal($first, null, '2026-10-11T23:57:00Z', '2026-10-11T23:58:00Z'),
            $this->proposal($first, null, '2026-10-12T00:00:00Z', '2026-10-12T00:01:00Z'),
        ], $result['sessions']);
        $this->assertSame(120, $result['summary']['unscheduled_seconds']);
        $this->assertSame($second->id, $result['work_results'][1]['task_id']);
        $this->assertSame(0, $result['work_results'][1]['allocated_seconds']);
        $this->assertContains('daily_study_limit_exceeded', $result['work_results'][1]['reason_codes']);
        $this->assertContains('weekly_study_limit_exceeded', $result['work_results'][1]['reason_codes']);
        $this->assertValidBatch($user, $result);
    }

    public function test_subtasks_share_projected_breaks_and_minimum_duration_with_partial_workload(): void
    {
        $user = $this->user();
        $task = $this->task($user);
        $first = $this->child($task, ['position' => 1]);
        $second = $this->child($task, ['position' => 2]);
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 30, 'max_session_minutes' => 30,
            'preferred_break_minutes' => 15, 'max_daily_study_minutes' => 75]);
        $result = $this->plan($user);
        $this->assertSame([
            $this->proposal($task, $first, '2026-10-12T09:00:00Z', '2026-10-12T09:30:00Z'),
            $this->proposal($task, $first, '2026-10-12T09:45:00Z', '2026-10-12T10:15:00Z'),
        ], $result['sessions']);
        $this->assertSame($second->id, $result['work_results'][1]['subtask_id']);
        $this->assertSame(3600, $result['work_results'][1]['unscheduled_seconds']);
        $this->assertContains('daily_study_limit_exceeded', $result['work_results'][1]['reason_codes']);
        $this->assertValidBatch($user, $result);
    }

    public function test_stage_a_warnings_are_preserved_while_stale_plans_still_block_c9_capacity(): void
    {
        $user = $this->user();
        $task = $this->task($user);
        $this->persistedSession($user, StudySessionStatus::Planned, '2026-10-12 08:00:00', '2026-10-12 10:00:00', ['task_id' => $task->id]);
        $selection = app(PlanningWorkSelectionService::class)->select($user);
        $result = $this->plan($user);
        $this->assertSame($selection['warnings'], $result['warnings']);
        $this->assertSame('stale_planned_session', $result['warnings'][0]['code']);
        $this->assertSame(3600, $result['summary']['requested_seconds']);
        $this->assertSame([$this->proposal($task, null, '2026-10-12T10:00:00Z', '2026-10-12T11:00:00Z')], $result['sessions']);
        $this->assertValidBatch($user, $result);
    }

    #[DataProvider('dstCases')]
    public function test_kyiv_dst_local_membership_elapsed_workload_and_exact_horizon(string $date, string $windowStart, string $windowEnd, int $estimate, ?int $daily, array $expected): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01T00:00:00Z'));
        $user = $this->user('Europe/Kyiv');
        $user->studyAvailabilityWindows()->delete();
        $this->window($user, 7, $windowStart, $windowEnd);
        $task = $this->task($user, ['estimated_minutes' => $estimate]);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => $daily]);
        $result = $this->plan($user, $date, $date, $task->id);
        $this->assertSame(array_map(fn ($pair): array => $this->proposal($task, null, $pair[0], $pair[1]), $expected), $result['sessions']);
        $this->assertValidBatch($user, $result);
    }

    public static function dstCases(): array
    {
        return [
            ['2026-03-29', '03:15:00', '03:45:00', 30, null, []],
            ['2026-10-25', '03:15:00', '03:45:00', 60, null, [['2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z'], ['2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z']]],
            ['2026-10-25', '03:15:00', '03:45:00', 60, 45, [['2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z'], ['2026-10-25T01:15:00Z', '2026-10-25T01:30:00Z']]],
            ['2026-03-29', '00:00:00', '24:00:00', 1380, 1380, [['2026-03-28T22:00:00Z', '2026-03-29T21:00:00Z']]],
            ['2026-10-25', '00:00:00', '24:00:00', 1500, 1440, [['2026-10-24T21:00:00Z', '2026-10-25T21:00:00Z']]],
        ];
    }

    public function test_31_inclusive_dates_are_not_expanded_and_work_outside_horizon_is_unscheduled(): void
    {
        $user = $this->user();
        $user->studyAvailabilityWindows()->delete();
        foreach (range(1, 7) as $day) {
            $this->window($user, $day, '00:00:00', '24:00:00');
        }
        $task = $this->task($user, ['estimated_minutes' => 65535]);
        $result = $this->plan($user, '2026-10-13', '2026-11-12', $task->id);
        $this->assertSame([$this->proposal($task, null, '2026-10-13T00:00:00Z', '2026-11-13T00:00:00Z')], $result['sessions']);
        $this->assertSame(2678400, $result['summary']['allocated_seconds']);
        $this->assertSame(1253700, $result['summary']['unscheduled_seconds']);
        $this->assertSame(['no_available_study_time', 'planning_horizon_exhausted'], $result['work_results'][0]['reason_codes']);
        $this->assertValidBatch($user, $result);
    }

    public function test_skipped_local_date_returns_a_structured_empty_plan(): void
    {
        $this->travelTo(CarbonImmutable::parse('2011-12-01T00:00:00Z'));
        $user = $this->user('Pacific/Apia', allDays: true);
        $this->task($user);
        $result = $this->plan($user, '2011-12-30', '2011-12-30');
        $this->assertSame([], $result['sessions']);
        $this->assertSame(3600, $result['summary']['unscheduled_seconds']);
    }

    #[DataProvider('invalidDates')]
    public function test_date_validation_is_exact_and_bounded_before_context_reads(string $start, string $end): void
    {
        $user = $this->user();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->plan($user, $start, $end);
            $this->fail('Invalid dates must fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public static function invalidDates(): array
    {
        return [['2026-10-13', '2026-11-13'], ['2026-10-13', '2026-10-12'], ['2026-02-29', '2026-03-01'],
            ['2026-1-01', '2026-01-02'], [' 2026-10-12', '2026-10-12'], ['2026-10-12T00:00:00Z', '2026-10-12'], ['2026-10-00', '2026-10-12']];
    }

    public function test_fractional_planning_clock_never_rounds_an_already_started_second_into_new_capacity(): void
    {
        $user = $this->user();
        $this->task($user, ['estimated_minutes' => 1]);
        $this->travelTo(CarbonImmutable::parse('2026-10-12T09:00:00.500000Z'));
        $result = $this->plan($user);
        $this->assertSame($this->ts('2026-10-12T09:00:01Z'), $result['sessions'][0]['start']);
        $this->assertSame('2026-10-12T09:00:00.500000Z', $result['as_of_utc']);
        $past = $this->plan($user, '2026-10-05', '2026-10-05');
        $this->assertSame([], $past['sessions']);
        $this->assertSame(60, $past['summary']['unscheduled_seconds']);
    }

    public function test_stage_a_clock_remains_authoritative_if_clock_changes_during_context_loading(): void
    {
        $user = $this->user();
        $this->task($user);
        $moved = false;
        DB::listen(function ($query) use (&$moved): void {
            if (! $moved && str_contains($query->sql, 'from `planning_preferences`')) {
                $moved = true;
                $this->travelTo(CarbonImmutable::parse('2026-10-20T00:00:00Z'));
            }
        });
        $result = $this->plan($user);
        $this->assertSame('2026-10-12T08:00:00.500000Z', $result['as_of_utc']);
        $this->assertSame($this->ts('2026-10-12T09:00:00Z'), $result['sessions'][0]['start']);
    }

    public function test_trusted_user_persisted_timezone_and_scoped_sources_ignore_foreign_auth_and_cache(): void
    {
        $owner = $this->user('Europe/Kyiv');
        $task = $this->task($owner);
        $foreign = $this->user('America/New_York', allDays: true);
        $this->task($foreign, ['priority' => TaskPriority::High]);
        PlanningPreference::factory()->for($foreign)->create(['min_session_minutes' => 120]);
        Lesson::factory()->for($foreign)->create(['starts_at' => '2026-10-12 08:00:00', 'ends_at' => '2026-10-12 12:00:00']);
        $this->persistedSession($foreign, StudySessionStatus::Planned, '2026-10-12 08:00:00', '2026-10-12 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-12T05:00:00Z'));
        $expected = $this->plan($owner);
        $this->actingAs($foreign);
        foreach (['tasks', 'lessons', 'studySessions', 'planningPreference', 'studyAvailabilityWindows'] as $relation) {
            $owner->setRelation($relation, $foreign->$relation);
        }
        $owner->timezone = 'invalid cached timezone';
        $this->assertSame($expected, $this->plan($owner));
        $this->assertSame([$this->proposal($task, null, '2026-10-12T06:00:00Z', '2026-10-12T07:00:00Z')], $expected['sessions']);
    }

    #[DataProvider('invalidWorkReferences')]
    public function test_explicit_foreign_missing_deleted_and_incompatible_targets_remain_generic(string $case): void
    {
        $owner = $this->user();
        $task = $this->task($owner);
        $taskId = $task->id;
        $childId = null;
        if ($case === 'missing task') {
            $taskId = PHP_INT_MAX;
        } elseif ($case === 'foreign task') {
            $taskId = $this->task($this->user())->id;
        } elseif ($case === 'deleted task') {
            $task->delete();
        } elseif ($case === 'missing child') {
            $childId = PHP_INT_MAX;
        } elseif ($case === 'deleted child') {
            $child = $this->child($task);
            $child->delete();
            $childId = $child->id;
        } else {
            $childId = $this->child($this->task($case === 'foreign child' ? $this->user() : $owner))->id;
        }
        try {
            $this->plan($owner, taskId: $taskId, subtaskId: $childId);
            $this->fail('Invalid work must fail.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame([], $exception->getIds());
        }
    }

    public static function invalidWorkReferences(): array
    {
        return array_map(fn ($case): array => [$case], ['missing task', 'foreign task', 'deleted task', 'missing child', 'foreign child', 'wrong parent', 'deleted child']);
    }

    #[DataProvider('malformedIdentifiers')]
    public function test_identifiers_are_not_coerced(mixed $taskId, mixed $childId): void
    {
        $user = $this->user();
        $this->expectException(InvalidArgumentException::class);
        $this->plan($user, taskId: $taskId, subtaskId: $childId);
    }

    public static function malformedIdentifiers(): array
    {
        $cases = [[null, 1]];
        foreach (['1', 1.0, true, false, 0, -1, []] as $bad) {
            $cases[] = [$bad, null];
            $cases[] = [1, $bad];
        }

        return $cases;
    }

    #[DataProvider('corruptSources')]
    public function test_corrupted_owned_source_relationships_fail_closed(string $case): void
    {
        $user = $this->user();
        $target = $this->task($user);
        $foreign = $this->task($this->user(), ['title' => 'private foreign work']);
        $sourceTask = $this->task($user, ['estimated_minutes' => null]);
        $values = [];
        if ($case === 'foreign task') {
            $values['task_id'] = $foreign->id;
        } elseif ($case === 'foreign child') {
            $values['task_id'] = $sourceTask->id;
            $values['subtask_id'] = $this->child($foreign)->id;
        } else {
            $values['task_id'] = $sourceTask->id;
            $values['subtask_id'] = $this->child($target)->id;
        }
        $this->persistedSession($user, StudySessionStatus::Completed, '2026-10-12 07:00:00', '2026-10-12 08:00:00', $values);
        try {
            $this->plan($user, taskId: $target->id);
            $this->fail('Corrupted sources must fail.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('Invalid planning source relationships.', $exception->getMessage());
        }
    }

    public static function corruptSources(): array
    {
        return [['foreign task'], ['foreign child'], ['wrong parent']];
    }

    public function test_valid_deleted_work_history_still_contributes_c9_workload(): void
    {
        $user = $this->user();
        $target = $this->task($user);
        $historical = $this->task($user, ['estimated_minutes' => null]);
        $child = $this->child($historical);
        $this->persistedSession($user, StudySessionStatus::Completed, '2026-10-12 07:00:00', '2026-10-12 08:00:00', ['task_id' => $historical->id, 'subtask_id' => $child->id, 'actual_minutes' => 1]);
        $child->delete();
        $historical->delete();
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 90]);
        $this->assertSame(1800, $this->plan($user, taskId: $target->id)['summary']['allocated_seconds']);
    }

    public function test_reversed_real_database_retrieval_order_does_not_change_plan(): void
    {
        $user = $this->user();
        $task = $this->task($user);
        $this->child($task, ['position' => 2, 'estimated_minutes' => 30]);
        $this->child($task, ['position' => 1, 'estimated_minutes' => 30]);
        $this->task($user);
        $this->persistedSession($user, StudySessionStatus::Completed, '2026-10-12 07:00:00', '2026-10-12 08:00:00');
        $expected = $this->plan($user);
        foreach ([Task::class, Subtask::class, StudySession::class, Lesson::class, StudyAvailabilityWindow::class] as $model) {
            $model::addGlobalScope('reverse_planning_test', fn ($query) => $query->orderByDesc($query->getModel()->qualifyColumn($query->getModel()->getKeyName())));
        }
        try {
            $this->assertSame($expected, $this->plan($user));
        } finally {
            Task::clearBootedModels();
        }
    }

    public function test_plan_queries_are_bounded_independently_of_proposed_session_count(): void
    {
        $user = $this->user();
        $this->task($user, ['estimated_minutes' => 5]);
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 1]);
        $this->persistedSession($user, StudySessionStatus::Completed, '2026-10-12 07:00:00', '2026-10-12 08:00:00');
        [$small, $smallPlan] = $this->queries($user);
        for ($i = 0; $i < 19; $i++) {
            $this->task($user, ['estimated_minutes' => 5]);
        }
        [$large, $largePlan] = $this->queries($user);
        $this->assertCount(5, $smallPlan['sessions']);
        $this->assertCount(100, $largePlan['sessions']);
        $this->assertCount(count($small), $large);
        $this->assertCount(14, $large);
        foreach ($large as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
            $this->assertStringNotContainsString('select *', $query['query']);
            if (str_contains($query['query'], 'from `lessons`') || str_contains($query['query'], 'from `study_sessions`') || str_contains($query['query'], 'from `tasks`')) {
                $this->assertStringContainsString('`user_id` = ?', $query['query']);
                $this->assertSame($user->id, $query['bindings'][0]);
            }
        }
    }

    public function test_repeated_plan_is_select_only_and_preserves_all_tables_and_c10_results(): void
    {
        $user = $this->user();
        $task = $this->task($user);
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30, 'preferred_break_minutes' => 15]);
        $this->persistedSession($user, StudySessionStatus::Completed, '2026-10-12 07:00:00', '2026-10-12 08:00:00');
        Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $candidate = ['start' => $this->ts('2026-10-12T09:00:00Z'), 'end' => $this->ts('2026-10-12T09:30:00Z')];
        $evaluation = app(PlanningCandidateEvaluationService::class)->evaluate($user, $candidate, $task->id);
        $snapshot = $this->snapshot();
        $level = DB::transactionLevel();
        $first = $this->plan($user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame($first, $this->plan($user));
            }
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($evaluation, app(PlanningCandidateEvaluationService::class)->evaluate($user, $candidate, $task->id));
        $this->assertSame($snapshot, $this->snapshot());
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
        return Task::factory()->for($user)->create(['estimated_minutes' => 60,
            'completed_at' => ($values['status'] ?? null) === TaskStatus::Completed ? '2026-10-11 12:00:00' : null, ...$values]);
    }

    private function child(Task $task, array $values = []): Subtask
    {
        return Subtask::factory()->for($task)->create(['estimated_minutes' => 60,
            'completed_at' => ($values['status'] ?? null) === TaskStatus::Completed ? '2026-10-11 12:00:00' : null, ...$values]);
    }

    private function persistedSession(User $user, StudySessionStatus $status, string $start, string $end, array $values = []): StudySession
    {
        return StudySession::factory()->create(['user_id' => $user->id, 'task_id' => $values['task_id'] ?? Task::factory()->for($user)->create()->id,
            'status' => $status, 'starts_at' => $start, 'ends_at' => $end,
            'completed_at' => $status === StudySessionStatus::Completed ? $end : null, ...$values]);
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
            $this->assertSame([], app(PlanningConstraintService::class)->evaluate($candidate, $context['free_intervals'],
                [...$context['break_anchors'], ...$others], [...$context['workload_sessions'], ...$others],
                $context['timezone'], $context['preferences'], $deadlines[$session['task_id'].':'.($session['subtask_id'] ?? 'task')]));
            $this->assertGreaterThan(0, $session['duration_seconds']);
            $this->assertSame($session['duration_seconds'], $session['end'] - $session['start']);
            $this->assertGreaterThanOrEqual(new DateTimeImmutable($result['as_of_utc']), new DateTimeImmutable('@'.$session['start']));
        }
        foreach ($result['work_results'] as $item) {
            $this->assertSame($item['requested_seconds'], $item['allocated_seconds'] + $item['unscheduled_seconds']);
        }
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $rows = DB::table($table)->get()->map(fn ($row): array => (array) $row)->all();
            usort($rows, fn ($a, $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $result[$table] = $rows;
        }
        ksort($result);

        return $result;
    }

    private function queries(User $user): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $this->plan($user);

            return [DB::getQueryLog(), $result];
        } finally {
            DB::disableQueryLog();
        }
    }
}
