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
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\WorkloadAdviceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkloadAdviceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-08T08:00:00.500000Z'));
    }

    private function url(array $query = []): string
    {
        return '/api/planning/workload-advice?'.http_build_query(array_replace([
            'start_date' => '2026-11-09', 'end_date' => '2026-11-09',
        ], $query));
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
        $this->actingAs($user, 'web')->getJson($this->url())->assertOk()->assertExactJson(['data' => [
            'as_of_utc' => '2026-11-08T08:00:00.500000Z', 'timezone' => 'Europe/Kyiv',
            'period' => ['start_date' => '2026-11-09', 'end_date' => '2026-11-09'],
            'summary' => ['requested_seconds' => 0, 'allocated_seconds' => 0, 'unscheduled_seconds' => 0, 'fully_allocated' => true],
            'alerts' => [], 'work_advice' => [], 'excluded_items' => [], 'warnings' => [], 'persisted_workload_violations' => [],
        ]]);
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
        return [
            ['full', 3600, '', 'follow_planning_order'],
            ['partial', 3600, 'no_available_study_time', 'review_study_availability'],
            ['no availability', 0, 'no_available_study_time', 'review_study_availability'],
            ['deadline', 1800, 'deadline_capacity_exhausted', 'review_deadline'],
            ['daily', 3600, 'daily_study_limit_exceeded', 'review_study_limits'],
            ['weekly', 3600, 'weekly_study_limit_exceeded', 'review_study_limits'],
            ['duration', 0, 'below_minimum_session_duration', 'review_session_duration'],
        ];
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
        $this->assertSame([[$parent->id, $first->id], [$parent->id, $second->id], [$early->id, null], [$high->id, null], [$low->id, null], [$noDeadline->id, null]],
            array_map(fn ($item) => [$item['task_id'], $item['subtask_id']], $data['work_advice']));
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
            StudySession::factory()->for($task)->create(['status' => 'completed', 'starts_at' => $start,
                'ends_at' => CarbonImmutable::parse($start, 'UTC')->addMinutes($minutes), 'completed_at' => $start, 'actual_minutes' => 1]);
        }
        foreach (['missed', 'cancelled', 'rescheduled'] as $status) {
            StudySession::factory()->for($task)->create(['status' => $status, 'starts_at' => $dates[0], 'ends_at' => CarbonImmutable::parse($dates[0], 'UTC')->addHours(5)]);
        }
        $query = $kind === 'day' ? [] : ['start_date' => '2025-12-29', 'end_date' => '2025-12-29'];
        $data = $this->advice($query);
        $code = $kind === 'day' ? 'daily_study_limit_exceeded' : 'weekly_study_limit_exceeded';
        $this->assertSame([['code' => $code, 'period_kind' => $kind === 'day' ? 'day' : 'iso_week',
            'period' => $kind === 'day' ? '2026-11-09' : '2026-01', 'scheduled_seconds' => $minutes * 120,
            'limit_seconds' => $kind === 'day' ? 3600 : 5400, 'excess_seconds' => $kind === 'day' ? 1200 : 1800]], $data['persisted_workload_violations']);
        $this->assertSame(['code' => $code, 'severity' => 'warning', 'source' => 'persisted_workload',
            'affected_seconds' => $kind === 'day' ? 1200 : 1800, 'affected_work_count' => 0], $data['alerts'][1]);
        $this->assertTrue($data['summary']['fully_allocated']);
        $this->assertSame([], $data['work_advice']);
    }

    public static function persistedCases(): array
    {
        return [['day'], ['week']];
    }

    public function test_completed_effort_future_reservations_and_owner_boundaries_are_shared_with_planning(): void
    {
        [$user, $task] = $this->fixture(['estimated_minutes' => 120, 'status' => 'missed']);
        StudySession::factory()->for($task)->create(['status' => 'completed', 'starts_at' => '2026-11-07 09:00:00',
            'ends_at' => '2026-11-07 10:00:00', 'actual_minutes' => 30, 'completed_at' => '2026-11-07 10:00:00']);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-11-10 09:00:00', 'ends_at' => '2026-11-10 10:00:00']);
        $foreign = Task::factory()->create(['estimated_minutes' => 600, 'priority' => 3]);
        Lesson::factory()->for($foreign->user)->create(['starts_at' => '2026-11-09 09:00:00', 'ends_at' => '2026-11-09 12:00:00']);
        StudySession::factory()->for($foreign)->create(['starts_at' => '2026-11-09 09:00:00', 'ends_at' => '2026-11-09 12:00:00']);
        $data = $this->advice();
        $this->assertSame([$task->id], array_column($data['work_advice'], 'task_id'));
        $this->assertSame(['requested_seconds' => 1800, 'allocated_seconds' => 1800, 'unscheduled_seconds' => 0, 'fully_allocated' => true], $data['summary']);
        $this->assertSame([], $data['warnings']);
    }

    public function test_over_reserved_effort_warning_does_not_imply_daily_or_weekly_overload(): void
    {
        [$user, $task] = $this->fixture(['estimated_minutes' => 30], '12:00:00');
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 120, 'max_weekly_study_minutes' => 600]);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-11-09 09:00:00', 'ends_at' => '2026-11-09 10:30:00']);
        $data = $this->advice();
        $this->assertContains('future_reservation_exceeds_remaining_work', array_column($data['warnings'], 'code'));
        $this->assertSame([], $data['persisted_workload_violations']);
        $this->assertSame(['excluded_work'], array_column($data['alerts'], 'code'));
        $this->assertSame([], $data['work_advice']);
        $this->assertSame([], $data['excluded_items'][0]['recommendation_codes']);
    }

    #[DataProvider('calendarCases')]
    public function test_persisted_timezone_dst_and_skipped_dates_follow_current_local_calendar(string $zone, string $date, int $day, int $minutes, int $allocated): void
    {
        $this->travelTo(CarbonImmutable::parse($date, 'UTC')->subDays(2));
        $user = User::factory()->create(['timezone' => $zone]);
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => '00:00:00', 'ends_at' => '24:00:00']);
        Task::factory()->for($user)->create(['estimated_minutes' => $minutes]);
        // A stale caller timezone is not a substitute for persisted IANA settings.
        $user->timezone = 'Pacific/Honolulu';
        $data = app(WorkloadAdviceService::class)->analyze($user, $date, $date);
        $this->assertSame($zone, $data['timezone']);
        $this->assertSame($allocated, $data['summary']['allocated_seconds']);
        $this->assertSame([], $data['persisted_workload_violations']);
    }

    public static function calendarCases(): array
    {
        return [['Europe/Kyiv', '2026-03-29', 7, 1440, 82800], ['Europe/Kyiv', '2026-10-25', 7, 1440, 86400], ['Pacific/Apia', '2011-12-30', 5, 60, 0]];
    }

    public function test_read_only_snapshot_no_locks_tokens_jobs_notifications_or_provider_calls_and_constant_query_count(): void
    {
        [$user, $task] = $this->fixture(['estimated_minutes' => 60], '12:00:00');
        StudySession::factory()->for($task)->create(['starts_at' => '2026-11-09 11:00:00', 'ends_at' => '2026-11-09 11:01:00']);
        $this->mock(StudyPlanPersistenceService::class)->shouldNotReceive('preview');
        Bus::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake();
        $before = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->advice();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('for update', strtolower($query['query']));
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertStringNotContainsString('preview_token', json_encode($data));
        $this->assertStringNotContainsString('expires_at', json_encode($data));
        $this->assertArrayNotHasKey('sessions', $data);
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Http::assertNothingSent();
        $count = count($queries);
        Task::factory()->for($user)->count(20)->create(['estimated_minutes' => 1]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->advice();
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($count, $largeCount);
    }

    public function test_authentication_precedes_validation_unknown_queries_are_rejected_and_body_cannot_override_owner(): void
    {
        $this->getJson('/api/planning/workload-advice')->assertUnauthorized();
        [$user] = $this->fixture(['estimated_minutes' => 60]);
        $foreign = User::factory()->create();
        $this->getJson('/api/planning/workload-advice')->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        foreach (['user_id' => $foreign->id, 'timezone' => 'UTC', 'task_id' => 1] as $field => $value) {
            $this->getJson($this->url([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->json('GET', $this->url(), ['user_id' => $foreign->id, 'start_date' => '2020-01-01'])
            ->assertOk()->assertJsonPath('data.period.start_date', '2026-11-09')->assertJsonPath('data.summary.requested_seconds', 3600);
        $this->json('GET', '/api/planning/workload-advice', ['start_date' => '2026-11-09', 'end_date' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    #[DataProvider('invalidDates')]
    public function test_exact_query_dates_and_range_validation(array $query, string $field): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson($this->url($query))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidDates(): array
    {
        $cases = [];
        foreach ([' 2026-11-09', '2026-11-09 ', '2026-11-9', '2026-02-30', '0000-01-01', '', ['2026-11-09']] as $value) {
            $cases[] = [['start_date' => $value], 'start_date'];
        }

        return [...$cases, [['end_date' => '2026-11-08'], 'end_date'], [['end_date' => '2026-12-10'], 'end_date']];
    }

    public function test_31_dates_are_accepted_and_corrupt_source_fails_closed_even_with_debug(): void
    {
        [$user, $task] = $this->fixture();
        $this->advice(['end_date' => '2026-12-09']);
        $foreign = Task::factory()->create();
        StudySession::factory()->for($foreign)->create(['user_id' => $user->id, 'starts_at' => '2026-11-09 09:00:00', 'ends_at' => '2026-11-09 10:00:00']);
        config(['app.debug' => true]);
        $this->getJson($this->url())->assertStatus(500)->assertExactJson(['message' => 'The workload advice could not be processed.']);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false) as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $result[$table] = $rows;
        }
        ksort($result);

        return $result;
    }
}
