<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsProgressComparison;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactProgressComparisonApiTest extends TestCase
{
    use AssertsProgressComparison, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00.123456Z'));
    }

    #[DataProvider('validPeriods')]
    public function test_empty_contract_for_valid_one_day_31_day_nonadjacent_year_over_year_and_storage_edge_periods(array $dates): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Europe/Kyiv']), 'web');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->getJson($this->url($dates))->assertOk()->json('data');
        $this->assertCount(4, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(['as_of_utc', 'timezone', 'comparison', 'baseline', 'current', 'delta', 'observations'], array_keys($data));
        $this->assertSame('2026-10-15T12:00:00.123456Z', $data['as_of_utc']);
        $this->assertSame('Europe/Kyiv', $data['timezone']);
        $this->assertSame(['equal_nominal_days' => true, 'session_attribution' => 'starts_at', 'historical_snapshot' => false], $data['comparison']);
        foreach (['baseline', 'current'] as $side) {
            $this->assertSame(['period' => ['start_date' => $dates[$side.'_start_date'], 'end_date' => $dates[$side.'_end_date']], 'totals' => $this->emptyMetrics()], $data[$side]);
        }
        $this->assertSame($this->emptyMetrics(), $data['delta']);
        $this->assertSame(['task_completions' => 'unchanged', 'subtask_completions' => 'unchanged', 'missed_sessions' => 'unchanged', 'known_actual_minutes' => 'unchanged', 'baseline_has_unknown_actual_minutes' => false, 'current_has_unknown_actual_minutes' => false], $data['observations']);
        $this->reconcile($data);
    }

    public static function validPeriods(): array
    {
        return [0 => [['baseline_start_date' => '2026-09-01', 'baseline_end_date' => '2026-09-01', 'current_start_date' => '2026-09-02', 'current_end_date' => '2026-09-02']]];
    }

    #[DataProvider('invalidQueries')]
    public function test_strict_calendar_unknown_query_and_pair_validation(array $parameters, array $errors): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson($this->url($parameters))->assertUnprocessable()->assertJsonValidationErrors($errors);
    }

    public static function invalidQueries(): array
    {
        return [0 => [['baseline_start_date' => ''], [0 => 'baseline_start_date']]];
    }

    public function test_all_four_query_dates_required_and_body_cannot_supply_or_override_them(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/statistics/reports/comparison')->assertUnprocessable()->assertJsonValidationErrors(array_keys($this->dates()));
        $this->json('GET', '/api/statistics/reports/comparison', $this->dates())->assertUnprocessable()->assertJsonValidationErrors(array_keys($this->dates()));
        foreach ($this->dates() as $key => $value) {
            $this->json('GET', '/api/statistics/reports/comparison?'.http_build_query(array_diff_key($this->dates(), [$key => 0])), [$key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->json('GET', $this->url(), ['baseline_start_date' => '2000-01-01', 'current_end_date' => '9999-12-31', 'user_id' => 999, 'timezone' => 'Pacific/Apia'])->assertOk()->assertJsonPath('data.baseline.period.start_date', '2026-09-01')->assertJsonPath('data.current.period.end_date', '2026-10-07')->assertJsonPath('data.timezone', 'UTC');
    }

    public function test_all_persisted_metrics_signed_deltas_and_unknown_actual_flags_reconcile_both_periods(): void
    {
        $user = User::factory()->create();
        foreach (['2026-09-01', '2026-10-01'] as $index => $date) {
            $parent = Task::factory()->for($user)->create();
            foreach ([$date.' 12:00:01', $date.' 12:00:00', $date.' 11:59:59', null] as $deadline) {
                Task::factory()->for($user)->count($index + 1)->create(['status' => 'completed', 'completed_at' => $date.' 12:00:00', 'deadline_at' => $deadline]);
                Subtask::factory()->for($parent)->count(2 - $index)->create(['status' => 'completed', 'completed_at' => $date.' 12:00:00', 'deadline_at' => $deadline]);
            }
            foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
                StudySession::factory()->for($parent)->count($status === 'missed' ? 2 - $index : $index + 1)->create(['status' => $status, 'starts_at' => $date.' 23:59:59', 'ends_at' => substr($date, 0, 8).'02 01:00:00', 'completed_at' => $status === 'completed' ? $date.' 23:59:59' : null, 'actual_minutes' => $status === 'completed' && $index === 1 ? 25 : null]);
            }
        }
        $data = $this->actingAs($user, 'web')->getJson($this->url())->assertOk()->json('data');
        $this->assertSame(['total' => 4, 'on_time' => 2, 'late' => 1, 'without_deadline' => 1], $data['baseline']['totals']['tasks_completed']);
        $this->assertSame(['total' => 8, 'on_time' => 4, 'late' => 2, 'without_deadline' => 2], $data['current']['totals']['tasks_completed']);
        $this->assertSame(8, $data['baseline']['totals']['subtasks_completed']['total']);
        $this->assertSame(4, $data['current']['totals']['subtasks_completed']['total']);
        $this->assertSame(['task_completions' => 'increased', 'subtask_completions' => 'decreased', 'missed_sessions' => 'decreased', 'known_actual_minutes' => 'increased', 'baseline_has_unknown_actual_minutes' => true, 'current_has_unknown_actual_minutes' => false], $data['observations']);
        foreach (['planned', 'completed', 'rescheduled', 'cancelled'] as $status) {
            $this->assertSame(['count' => 1, 'scheduled_seconds' => 3601], $data['baseline']['totals']['study_sessions'][$status]);
            $this->assertSame(['count' => 2, 'scheduled_seconds' => 7202], $data['current']['totals']['study_sessions'][$status]);
        }
        $this->assertSame(0, $data['baseline']['totals']['study_sessions']['completed_actual_minutes']);
        $this->assertSame(50, $data['current']['totals']['study_sessions']['completed_actual_minutes']);
        $this->reconcile($data);
    }

    #[DataProvider('calendarCases')]
    public function test_exact_dst_skipped_and_disjoint_historical_membership(string $zone, string $baseline, string $current, array $instants, array $counts): void
    {
        $user = User::factory()->create(['timezone' => $zone]);
        $parent = Task::factory()->for($user)->create();
        foreach ($instants as $instant) {
            Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => $instant]);
            Subtask::factory()->for($parent)->create(['status' => 'completed', 'completed_at' => $instant]);
            StudySession::factory()->for($parent)->create(['starts_at' => $instant, 'ends_at' => CarbonImmutable::parse($instant, 'UTC')->addSeconds(61)->format('Y-m-d H:i:s')]);
        }
        $data = $this->actingAs($user, 'web')->getJson($this->url(['baseline_start_date' => $baseline, 'baseline_end_date' => $baseline, 'current_start_date' => $current, 'current_end_date' => $current]))->assertOk()->json('data');
        foreach (['baseline', 'current'] as $index => $side) {
            $this->assertSame($counts[$index], $data[$side]['totals']['tasks_completed']['total']);
            $this->assertSame($counts[$index], $data[$side]['totals']['subtasks_completed']['total']);
            $this->assertSame(['count' => $counts[$index], 'scheduled_seconds' => $counts[$index] * 61], $data[$side]['totals']['study_sessions']['planned']);
        }
        $this->reconcile($data);
    }

    public static function calendarCases(): array
    {
        return [0 => ['Europe/Kyiv', '2026-03-29', '2026-10-25', [0 => '2026-03-28 22:00:00', 1 => '2026-03-29 01:00:00', 2 => '2026-03-29 21:00:00', 3 => '2026-10-25 00:30:00', 4 => '2026-10-25 01:30:00', 5 => '2026-10-25 22:00:00'], [0 => 2, 1 => 2]]];
    }

    public function test_equal_nominal_dst_days_preserve_different_elapsed_seconds(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($user)->create();
        foreach ([['2026-03-28 22:00:00', '2026-03-29 21:00:00'], ['2026-10-24 21:00:00', '2026-10-25 22:00:00']] as [$start, $end]) {
            StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end]);
        }
        $data = $this->actingAs($user, 'web')->getJson($this->url(['baseline_start_date' => '2026-03-29', 'baseline_end_date' => '2026-03-29', 'current_start_date' => '2026-10-25', 'current_end_date' => '2026-10-25']))->assertOk()->json('data');
        $this->assertSame(82800, $data['baseline']['totals']['study_sessions']['planned']['scheduled_seconds']);
        $this->assertSame(90000, $data['current']['totals']['study_sessions']['planned']['scheduled_seconds']);
        $this->assertSame(7200, $data['delta']['study_sessions']['planned']['scheduled_seconds']);
        $this->reconcile($data);
    }
}
