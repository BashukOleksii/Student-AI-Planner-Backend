<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Progress\ProgressComparisonService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsProgressComparison;
use Tests\TestCase;
use Tests\Unit\ProgressComparisonServiceTest;

class ProgressComparisonApiTest extends TestCase
{
    use AssertsProgressComparison, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00.123456Z'));
    }

    public function test_authentication_precedes_validation_and_only_read_route_exists(): void
    {
        $this->getJson('/api/statistics/reports/comparison')->assertUnauthorized();
        $this->getJson($this->url())->assertUnauthorized();
        $this->assertSame('/api/statistics/reports/comparison', route('statistics.reports.comparison', [], false));
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/statistics/reports/comparison')->assertStatus(405);
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
            $this->assertSame(['period' => ['start_date' => $dates[$side.'_start_date'], 'end_date' => $dates[$side.'_end_date']],
                'totals' => $this->emptyMetrics()], $data[$side]);
        }
        $this->assertSame($this->emptyMetrics(), $data['delta']);
        $this->assertSame(['task_completions' => 'unchanged', 'subtask_completions' => 'unchanged', 'missed_sessions' => 'unchanged',
            'known_actual_minutes' => 'unchanged', 'baseline_has_unknown_actual_minutes' => false,
            'current_has_unknown_actual_minutes' => false], $data['observations']);
        $this->reconcile($data);
    }

    public static function validPeriods(): iterable
    {
        foreach ([['2026-09-01', '2026-09-01', '2026-09-02', '2026-09-02'],
            ['2025-10-01', '2025-10-31', '2026-10-01', '2026-10-31'],
            ['2026-08-01', '2026-08-07', '2026-10-01', '2026-10-07'],
            ['0001-01-01', '0001-01-01', '9999-12-31', '9999-12-31'],
            ['2028-02-01', '2028-02-29', '2028-03-01', '2028-03-29']] as $dates) {
            yield [array_combine(['baseline_start_date', 'baseline_end_date', 'current_start_date', 'current_end_date'], $dates)];
        }
    }

    #[DataProvider('invalidQueries')]
    public function test_strict_calendar_unknown_query_and_pair_validation(array $parameters, array $errors): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson($this->url($parameters))
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
    }

    public static function invalidQueries(): iterable
    {
        foreach (['baseline_start_date', 'baseline_end_date', 'current_start_date', 'current_end_date'] as $key) {
            foreach (['', ' 2026-09-01', '2026-10-07 ', '2026-2-01', '2026-02-29', '2026-04-31',
                '0000-01-01', '2026-10-01T00:00:00Z', '2026-10-01+03:00', '01-10-2026', ['2026-10-01']] as $value) {
                yield [[$key => $value], [$key]];
            }
        }
        foreach (['user_id', 'timezone', 'status', 'totals', 'page', 'start_date', 'end_date', 'task_id'] as $key) {
            yield [[$key => '1'], [$key]];
        }
        yield [['baseline_end_date' => '2026-08-31'], ['baseline_end_date']];
        yield [['current_end_date' => '2026-09-30'], ['current_end_date']];
        yield [['baseline_end_date' => '2026-10-02'], ['baseline_end_date']];
        yield [['current_end_date' => '2026-11-01'], ['current_end_date']];
        yield [['current_start_date' => '2026-09-07', 'current_end_date' => '2026-09-13'], ['current_start_date']];
        yield [['current_start_date' => '2026-09-01', 'current_end_date' => '2026-09-07'], ['current_start_date']];
        yield [['current_start_date' => '2026-08-01', 'current_end_date' => '2026-08-07'], ['current_start_date']];
        yield [['current_end_date' => '2026-10-08'], ['current_start_date']];
    }

    public function test_all_four_query_dates_required_and_body_cannot_supply_or_override_them(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/statistics/reports/comparison')->assertUnprocessable()->assertJsonValidationErrors(array_keys($this->dates()));
        $this->json('GET', '/api/statistics/reports/comparison', $this->dates())->assertUnprocessable()->assertJsonValidationErrors(array_keys($this->dates()));
        foreach ($this->dates() as $key => $value) {
            $this->json('GET', '/api/statistics/reports/comparison?'.http_build_query(array_diff_key($this->dates(), [$key => 0])), [$key => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->json('GET', $this->url(), ['baseline_start_date' => '2000-01-01', 'current_end_date' => '9999-12-31', 'user_id' => 999, 'timezone' => 'Pacific/Apia'])
            ->assertOk()->assertJsonPath('data.baseline.period.start_date', '2026-09-01')->assertJsonPath('data.current.period.end_date', '2026-10-07')
            ->assertJsonPath('data.timezone', 'UTC');
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
                StudySession::factory()->for($parent)->count($status === 'missed' ? 2 - $index : $index + 1)->create([
                    'status' => $status, 'starts_at' => $date.' 23:59:59', 'ends_at' => substr($date, 0, 8).'02 01:00:00',
                    'completed_at' => $status === 'completed' ? $date.' 23:59:59' : null,
                    'actual_minutes' => $status === 'completed' && $index === 1 ? 25 : null]);
            }
        }
        $data = $this->actingAs($user, 'web')->getJson($this->url())->assertOk()->json('data');
        $this->assertSame(['total' => 4, 'on_time' => 2, 'late' => 1, 'without_deadline' => 1], $data['baseline']['totals']['tasks_completed']);
        $this->assertSame(['total' => 8, 'on_time' => 4, 'late' => 2, 'without_deadline' => 2], $data['current']['totals']['tasks_completed']);
        $this->assertSame(8, $data['baseline']['totals']['subtasks_completed']['total']);
        $this->assertSame(4, $data['current']['totals']['subtasks_completed']['total']);
        $this->assertSame(['task_completions' => 'increased', 'subtask_completions' => 'decreased', 'missed_sessions' => 'decreased',
            'known_actual_minutes' => 'increased', 'baseline_has_unknown_actual_minutes' => true, 'current_has_unknown_actual_minutes' => false], $data['observations']);
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
        $data = $this->actingAs($user, 'web')->getJson($this->url(['baseline_start_date' => $baseline, 'baseline_end_date' => $baseline,
            'current_start_date' => $current, 'current_end_date' => $current]))->assertOk()->json('data');
        foreach (['baseline', 'current'] as $index => $side) {
            $this->assertSame($counts[$index], $data[$side]['totals']['tasks_completed']['total']);
            $this->assertSame($counts[$index], $data[$side]['totals']['subtasks_completed']['total']);
            $this->assertSame(['count' => $counts[$index], 'scheduled_seconds' => $counts[$index] * 61], $data[$side]['totals']['study_sessions']['planned']);
        }
        $this->reconcile($data);
    }

    public static function calendarCases(): array
    {
        return ProgressComparisonServiceTest::calendarCases();
    }

    public function test_equal_nominal_dst_days_preserve_different_elapsed_seconds(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($user)->create();
        foreach ([['2026-03-28 22:00:00', '2026-03-29 21:00:00'], ['2026-10-24 21:00:00', '2026-10-25 22:00:00']] as [$start, $end]) {
            StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end]);
        }
        $data = $this->actingAs($user, 'web')->getJson($this->url(['baseline_start_date' => '2026-03-29', 'baseline_end_date' => '2026-03-29',
            'current_start_date' => '2026-10-25', 'current_end_date' => '2026-10-25']))->assertOk()->json('data');
        $this->assertSame(82800, $data['baseline']['totals']['study_sessions']['planned']['scheduled_seconds']);
        $this->assertSame(90000, $data['current']['totals']['study_sessions']['planned']['scheduled_seconds']);
        $this->assertSame(7200, $data['delta']['study_sessions']['planned']['scheduled_seconds']);
        $this->reconcile($data);
    }

    public function test_half_open_start_membership_cross_midnight_and_final_storage_second(): void
    {
        $task = Task::factory()->create();
        foreach (['2026-08-31 23:59:59', '2026-09-01 00:00:00', '2026-09-01 23:59:59', '2026-09-02 00:00:00',
            '9999-12-31 23:59:59'] as $instant) {
            Task::factory()->for($task->user)->create(['status' => 'completed', 'completed_at' => $instant]);
            Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => $instant]);
        }
        foreach ([['2026-08-31 23:59:59', '2026-09-01 01:00:00'], ['2026-09-01 23:59:59', '2026-09-02 01:00:00'],
            ['2026-09-02 00:00:00', '2026-09-02 01:00:00'], ['9999-12-31 23:59:58', '9999-12-31 23:59:59']] as [$start, $end]) {
            StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end]);
        }
        $data = $this->actingAs($task->user, 'web')->getJson($this->url(['baseline_end_date' => '2026-09-01',
            'current_start_date' => '9999-12-31', 'current_end_date' => '9999-12-31']))->assertOk()->json('data');
        $this->assertSame(2, $data['baseline']['totals']['tasks_completed']['total']);
        $this->assertSame(2, $data['baseline']['totals']['subtasks_completed']['total']);
        $this->assertSame(['count' => 1, 'scheduled_seconds' => 3601], $data['baseline']['totals']['study_sessions']['planned']);
        $this->assertSame(1, $data['current']['totals']['tasks_completed']['total']);
        $this->assertSame(1, $data['current']['totals']['subtasks_completed']['total']);
        $this->assertSame(['count' => 1, 'scheduled_seconds' => 1], $data['current']['totals']['study_sessions']['planned']);
        $this->reconcile($data);
    }

    public function test_fresh_owner_timezone_and_foreign_owner_isolation(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $owner->load(['tasks', 'studySessions']);
        User::whereKey($owner->id)->update(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($owner)->create(['status' => 'completed', 'completed_at' => '2026-09-30 21:00:00']);
        Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-09-30 21:00:00']);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-09-30 21:00:00', 'ends_at' => '2026-09-30 22:00:00']);
        foreach (['2026-09-01', '2026-10-01'] as $date) {
            $foreign = Task::factory()->create(['status' => 'completed', 'completed_at' => $date.' 12:00:00']);
            Subtask::factory()->for($foreign)->count(5)->create(['status' => 'completed', 'completed_at' => $date.' 12:00:00']);
            StudySession::factory()->for($foreign)->count(5)->create(['starts_at' => $date.' 09:00:00', 'ends_at' => $date.' 10:00:00']);
        }
        $data = app(ProgressComparisonService::class)->calculate($owner, ...array_values($this->dates()));
        $this->assertSame('Europe/Kyiv', $data['timezone']);
        $this->assertSame($this->emptyMetrics(), $data['baseline']['totals']);
        $this->assertSame(1, $data['current']['totals']['tasks_completed']['total']);
        $this->assertSame(1, $data['current']['totals']['subtasks_completed']['total']);
        $this->assertSame(1, $data['current']['totals']['study_sessions']['planned']['count']);
        $this->actingAs($owner, 'web');
        $this->reconcile($data);
    }

    public function test_mutable_history_deadline_reopening_and_soft_deletion_do_not_reconstruct_old_state(): void
    {
        $task = Task::factory()->create(['status' => 'completed', 'completed_at' => '2026-09-01 12:00:00', 'deadline_at' => '2026-09-01 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-09-01 12:00:00']);
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'status' => 'completed',
            'starts_at' => '2026-09-01 09:00:00', 'ends_at' => '2026-09-01 10:00:00', 'completed_at' => '2026-09-01 10:00:00', 'actual_minutes' => 25]);
        $this->actingAs($task->user, 'web')->getJson($this->url())->assertOk()->assertJsonPath('data.baseline.totals.tasks_completed.on_time', 1);
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-09-01T11:59:59Z'])->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.baseline.totals.tasks_completed.late', 1);
        $this->postJson('/api/tasks/'.$task->id.'/reopen')->assertOk();
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('data.baseline.totals.tasks_completed.total', 0)
            ->assertJsonPath('data.baseline.totals.subtasks_completed.total', 1)->json('data');
        $this->reconcile($data);
        $this->postJson('/api/tasks/'.$task->id.'/subtasks/'.$child->id.'/reopen')->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.baseline.totals.subtasks_completed.total', 0);
        $child->update(['status' => 'completed', 'completed_at' => '2026-09-01 12:00:00']);
        $child->delete();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.baseline.totals.subtasks_completed.total', 0);
        $child->restore();
        $task->delete(); // Active legacy child is excluded; historical sessions still validate.
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('data.baseline.totals.subtasks_completed.total', 0)
            ->assertJsonPath('data.baseline.totals.study_sessions.completed_actual_minutes', 25)->json('data');
        $this->reconcile($data);
    }

    #[DataProvider('corruptReferences')]
    public function test_selected_corrupt_history_fails_closed_in_either_period_without_private_errors_or_writes(string $kind, string $status, string $date): void
    {
        config(['app.debug' => true]);
        $task = Task::factory()->create();
        $other = Task::factory()->for($task->user)->create();
        $foreign = Task::factory()->create();
        $child = Subtask::factory()->for($kind === 'foreign_child' ? $foreign : $other)->create();
        StudySession::factory()->for($kind === 'foreign_task' ? $foreign : $task)->create(['user_id' => $task->user_id,
            'subtask_id' => $kind === 'foreign_task' ? null : $child->id, 'status' => $status,
            'starts_at' => $date.' 09:00:00', 'ends_at' => $date.' 10:00:00',
            'completed_at' => $status === 'completed' ? $date.' 10:00:00' : null]);
        $snapshot = $this->snapshot();
        $this->actingAs($task->user, 'web')->getJson($this->url())->assertStatus(500)
            ->assertExactJson(['message' => 'The progress statistics could not be processed.']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function corruptReferences(): iterable
    {
        foreach (ProgressTrendsApiTest::corruptReferences() as [$kind, $status]) {
            foreach (['2026-09-01', '2026-10-01'] as $date) {
                yield [$kind, $status, $date];
            }
        }
    }

    public function test_exact_union_excludes_large_interperiod_gap_and_disjoint_historical_envelope_gap(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Juneau']);
        // Both corrupt sources are outside membership and must never be loaded or validated.
        foreach (['1867-10-18 10:00:00', '1900-01-01 00:00:00'] as $instant) {
            StudySession::factory()->create(['user_id' => $user->id, 'starts_at' => $instant,
                'ends_at' => CarbonImmutable::parse($instant, 'UTC')->addHour()->format('Y-m-d H:i:s')]);
        }
        $data = $this->actingAs($user, 'web')->getJson($this->url(['baseline_start_date' => '1867-10-18', 'baseline_end_date' => '1867-10-18',
            'current_start_date' => '2026-10-01', 'current_end_date' => '2026-10-01']))->assertOk()->json('data');
        $this->assertSame($this->emptyMetrics(), $data['baseline']['totals']);
        $this->assertSame($this->emptyMetrics(), $data['current']['totals']);
        $this->reconcile($data);
        User::whereKey($user->id)->update(['timezone' => 'Invalid/Timezone']);
        $this->getJson($this->url())->assertStatus(500)->assertExactJson(['message' => 'The progress statistics could not be processed.']);
    }

    public function test_constant_source_query_count_for_small_large_and_31_day_periods_and_no_writes_or_side_effects(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        Bus::fake();
        Notification::fake();
        Http::fake();
        Reminder::factory()->for($user)->create();
        foreach ([1, 100] as $size) {
            for ($i = 0; $i < $size; $i++) {
                foreach (['2026-08-01', '2026-10-01'] as $date) {
                    $task = Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => $date.' 12:00:00']);
                    $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => $date.' 12:00:00']);
                    StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => $date.' 09:00:00', 'ends_at' => $date.' 10:00:00']);
                }
            }
            foreach ([1, 31] as $length) {
                $parameters = ['baseline_start_date' => '2026-08-01', 'baseline_end_date' => '2026-08-'.sprintf('%02d', $length),
                    'current_end_date' => '2026-10-'.sprintf('%02d', $length)];
                $snapshot = $this->snapshot();
                DB::enableQueryLog();
                DB::flushQueryLog();
                $data = $this->getJson($this->url($parameters))->assertOk()->json('data');
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $this->assertCount(6, $queries);
                foreach ($queries as $query) {
                    $this->assertStringStartsWith('select ', strtolower($query['query']));
                    $this->assertStringNotContainsString('for update', $query['query']);
                    $this->assertStringNotContainsString('COUNT(*)', $query['query']);
                }
                $sourceQueries = array_filter($queries, fn ($query) => str_contains($query['query'], ' >= ?'));
                $this->assertCount(3, $sourceQueries);
                foreach ($sourceQueries as $query) {
                    $this->assertStringContainsString(' or ', $query['query']);
                    $this->assertCount(4, array_filter($query['bindings'], fn ($value) => is_string($value) && preg_match('/^2026-/', $value)));
                }
                foreach (['baseline', 'current'] as $side) {
                    $this->assertSame($size === 1 ? 1 : 101, $data[$side]['totals']['tasks_completed']['total']);
                    $this->assertSame($size === 1 ? 1 : 101, $data[$side]['totals']['subtasks_completed']['total']);
                }
                $this->assertSame($data, $this->getJson($this->url($parameters))->assertOk()->json('data'));
                $this->reconcile($data);
                $this->assertSame($snapshot, $this->snapshot());
            }
        }
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
