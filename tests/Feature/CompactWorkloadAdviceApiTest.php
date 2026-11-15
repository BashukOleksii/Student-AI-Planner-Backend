<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\StudyPlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactWorkloadAdviceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-08T08:00:00.500000Z'));
    }

    private function url(array $query = []): string
    {
        return '/api/planning/workload-advice?'.http_build_query(array_replace(['start_date' => '2026-11-09', 'end_date' => '2026-11-09'], $query));
    }

    private function fixture(array $taskValues = [], string $end = '10:00:00'): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => $end]);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => 120, ...$taskValues]);
        $this->actingAs($user, 'web');

        return [$user, $task];
    }

    private function advice(array $query = []): array
    {
        return $this->getJson($this->url($query))->assertOk()->json('data');
    }

    public function test_empty_exact_contract_has_no_tokens_or_materialized_preferences(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->actingAs($user, 'web')->getJson($this->url())->assertOk()->assertExactJson(['data' => ['as_of_utc' => '2026-11-08T08:00:00.500000Z', 'timezone' => 'Europe/Kyiv', 'period' => ['start_date' => '2026-11-09', 'end_date' => '2026-11-09'], 'summary' => ['requested_seconds' => 0, 'allocated_seconds' => 0, 'unscheduled_seconds' => 0, 'fully_allocated' => true], 'alerts' => [], 'work_advice' => [], 'excluded_items' => [], 'warnings' => [], 'persisted_workload_violations' => []]]);
        $this->assertDatabaseCount('planning_preferences', 0);
        $this->assertSame('/api/planning/workload-advice', route('planning.workload-advice', [], false));
    }

    #[DataProvider('allocationCases')]
    public function test_advice_matches_the_direct_planner_and_reports_only_observed_constraints(string $case, int $allocated, string $reason, string $recommendation): void
    {
        [$user, $task] = $this->fixture(end: '12:00:00');
        match ($case) {
            'partial' => $user->studyAvailabilityWindows()->update(['ends_at' => '10:00:00']),
            'no availability' => $user->studyAvailabilityWindows()->delete(),
            'deadline' => $task->update(['deadline_at' => '2026-11-09 09:30:00']),
            'daily' => PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 60]),
            'weekly' => PlanningPreference::factory()->for($user)->create(['max_weekly_study_minutes' => 60]),
            'duration' => [$task->update(['estimated_minutes' => 10]), PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 15])],
            'full' => $task->update(['estimated_minutes' => 60]),
        };
        $plan = app(StudyPlanningService::class)->plan($user, '2026-11-09', '2026-11-09');
        $data = $this->advice();
        $this->assertSame($plan['as_of_utc'], $data['as_of_utc']);
        $this->assertSame($plan['summary'], $data['summary']);
        $this->assertSame($plan['work_results'], array_map(fn ($item) => Arr::except($item, ['rank', 'recommendation_codes']), $data['work_advice']));
        $this->assertSame($plan['excluded_items'], array_map(fn ($item) => Arr::except($item, ['recommendation_codes']), $data['excluded_items']));
        $this->assertSame($plan['warnings'], $data['warnings']);
        $this->assertSame($allocated, $data['summary']['allocated_seconds']);
        $this->assertSame(1, $data['work_advice'][0]['rank']);
        $this->assertContains($recommendation, $data['work_advice'][0]['recommendation_codes']);
        $this->assertSame([], $data['persisted_workload_violations']);
        $this->assertSame([], array_values(array_filter($data['alerts'], fn ($a) => $a['source'] === 'persisted_workload')));
        if ($reason !== '') {
            $this->assertContains($reason, $data['work_advice'][0]['reason_codes']);
            $this->assertContains($reason, array_column($data['alerts'], 'code'));
            $this->assertSame('unscheduled_work', $data['alerts'][0]['code']);
        } else {
            $this->assertSame([], $data['alerts']);
            $this->assertTrue($data['summary']['fully_allocated']);
        }
        if (! in_array($case, ['daily', 'weekly'], true)) {
            $this->assertSame([], array_intersect(['daily_study_limit_exceeded', 'weekly_study_limit_exceeded'], array_column($data['alerts'], 'code')));
        }
        if ($case === 'duration') {
            $this->assertSame(['below_minimum_session_duration'], $data['work_advice'][0]['reason_codes']);
        }
    }

    public static function allocationCases(): array
    {
        return [0 => ['full', 3600, '', 'follow_planning_order']];
    }

    public function test_missing_estimate_expiry_and_completed_work_are_excluded_while_missed_work_remains_actionable(): void
    {
        [$user, $missed] = $this->fixture(['status' => 'missed', 'estimated_minutes' => 30]);
        $unknown = Task::factory()->for($user)->create(['estimated_minutes' => null]);
        $expired = Task::factory()->for($user)->create(['estimated_minutes' => 30, 'deadline_at' => '2026-11-08 07:00:00']);
        $completed = Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => '2026-11-07 10:00:00']);
        $data = $this->advice();
        $this->assertSame([$missed->id], array_column($data['work_advice'], 'task_id'));
        $excluded = collect($data['excluded_items'])->keyBy('task_id');
        $this->assertSame(['supply_estimated_effort'], $excluded[$unknown->id]['recommendation_codes']);
        $this->assertNull($excluded[$unknown->id]['effort']['estimated_seconds']);
        $this->assertSame(['review_deadline'], $excluded[$expired->id]['recommendation_codes']);
        $this->assertSame([], $excluded[$completed->id]['recommendation_codes']);
        $this->assertSame(['code' => 'excluded_work', 'severity' => 'info', 'source' => 'selection', 'affected_seconds' => null, 'affected_work_count' => 3], $data['alerts'][0]);
        $this->assertTrue($data['summary']['fully_allocated']);
        $this->assertSame(1800, $data['summary']['requested_seconds']);
    }

    public function test_priority_rank_preserves_deadlines_parent_priority_and_child_positions_without_parent_duplicates(): void
    {
        [$user, $low] = $this->fixture(['estimated_minutes' => 10, 'priority' => 1, 'deadline_at' => '2026-11-10 12:00:00'], '12:00:00');
        $high = Task::factory()->for($user)->create(['estimated_minutes' => 10, 'priority' => 3, 'status' => 'missed', 'deadline_at' => '2026-11-10 12:00:00']);
        $early = Task::factory()->for($user)->create(['estimated_minutes' => 10, 'priority' => 1, 'deadline_at' => '2026-11-09 12:00:00']);
        $noDeadline = Task::factory()->for($user)->create(['estimated_minutes' => 10, 'priority' => 3]);
        $parent = Task::factory()->for($user)->create(['estimated_minutes' => 500, 'priority' => 2, 'deadline_at' => '2026-11-10 12:00:00']);
        $second = Subtask::factory()->for($parent)->create(['position' => 5, 'estimated_minutes' => 10, 'deadline_at' => '2026-11-09 11:00:00']);
        $first = Subtask::factory()->for($parent)->create(['position' => 1, 'estimated_minutes' => 10, 'deadline_at' => '2026-11-09 11:00:00']);
        Subtask::factory()->for($parent)->create(['position' => 6, 'status' => 'completed', 'completed_at' => '2026-11-07 10:00:00']);
        $data = $this->advice();
        $this->assertSame([[$parent->id, $first->id], [$parent->id, $second->id], [$early->id, null], [$high->id, null], [$low->id, null], [$noDeadline->id, null]], array_map(fn ($item) => [$item['task_id'], $item['subtask_id']], $data['work_advice']));
        $this->assertSame(range(1, 6), array_column($data['work_advice'], 'rank'));
        $this->assertSame(3600, $data['summary']['requested_seconds']);
        $this->assertSame($data, $this->advice());
    }

    #[DataProvider('persistedCases')]
    public function test_existing_overload_has_separate_measured_evidence_and_excludes_retired_sessions(string $kind): void
    {
        [$user, $task] = $this->fixture(['status' => 'completed', 'completed_at' => '2026-11-07 10:00:00']);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 60, 'max_weekly_study_minutes' => 90]);
        $dates = $kind === 'day' ? ['2026-11-09 08:00:00', '2026-11-09 08:00:00'] : ['2025-12-31 10:00:00', '2026-01-01 10:00:00'];
        $minutes = $kind === 'day' ? 40 : 60;
        foreach ($dates as $start) {
            StudySession::factory()->for($task)->create(['status' => 'completed', 'starts_at' => $start, 'ends_at' => CarbonImmutable::parse($start, 'UTC')->addMinutes($minutes), 'completed_at' => $start, 'actual_minutes' => 1]);
        }
        foreach (['missed', 'cancelled', 'rescheduled'] as $status) {
            StudySession::factory()->for($task)->create(['status' => $status, 'starts_at' => $dates[0], 'ends_at' => CarbonImmutable::parse($dates[0], 'UTC')->addHours(5)]);
        }
        $query = $kind === 'day' ? [] : ['start_date' => '2025-12-29', 'end_date' => '2025-12-29'];
        $data = $this->advice($query);
        $code = $kind === 'day' ? 'daily_study_limit_exceeded' : 'weekly_study_limit_exceeded';
        $this->assertSame([['code' => $code, 'period_kind' => $kind === 'day' ? 'day' : 'iso_week', 'period' => $kind === 'day' ? '2026-11-09' : '2026-01', 'scheduled_seconds' => $minutes * 120, 'limit_seconds' => $kind === 'day' ? 3600 : 5400, 'excess_seconds' => $kind === 'day' ? 1200 : 1800]], $data['persisted_workload_violations']);
        $this->assertSame(['code' => $code, 'severity' => 'warning', 'source' => 'persisted_workload', 'affected_seconds' => $kind === 'day' ? 1200 : 1800, 'affected_work_count' => 0], $data['alerts'][1]);
        $this->assertTrue($data['summary']['fully_allocated']);
        $this->assertSame([], $data['work_advice']);
    }

    public static function persistedCases(): array
    {
        return [0 => ['day']];
    }

    public function test_completed_effort_future_reservations_and_owner_boundaries_are_shared_with_planning(): void
    {
        [$user, $task] = $this->fixture(['estimated_minutes' => 120, 'status' => 'missed']);
        StudySession::factory()->for($task)->create(['status' => 'completed', 'starts_at' => '2026-11-07 09:00:00', 'ends_at' => '2026-11-07 10:00:00', 'actual_minutes' => 30, 'completed_at' => '2026-11-07 10:00:00']);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-11-10 09:00:00', 'ends_at' => '2026-11-10 10:00:00']);
        $foreign = Task::factory()->create(['estimated_minutes' => 600, 'priority' => 3]);
        Lesson::factory()->for($foreign->user)->create(['starts_at' => '2026-11-09 09:00:00', 'ends_at' => '2026-11-09 12:00:00']);
        StudySession::factory()->for($foreign)->create(['starts_at' => '2026-11-09 09:00:00', 'ends_at' => '2026-11-09 12:00:00']);
        $data = $this->advice();
        $this->assertSame([$task->id], array_column($data['work_advice'], 'task_id'));
        $this->assertSame(['requested_seconds' => 1800, 'allocated_seconds' => 1800, 'unscheduled_seconds' => 0, 'fully_allocated' => true], $data['summary']);
        $this->assertSame([], $data['warnings']);
    }
}
