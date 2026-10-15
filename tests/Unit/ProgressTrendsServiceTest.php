<?php

namespace Tests\Unit;

use App\Services\Planning\AvailabilityOccurrenceService;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\TimeIntervalService;
use App\Services\Progress\ProgressStatisticsService;
use App\Services\Progress\ProgressTrendsService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class ProgressTrendsServiceTest extends TestCase
{
    private function services(): array
    {
        $intervals = new TimeIntervalService;
        $availability = new AvailabilityOccurrenceService($intervals);
        $context = new PlanningContextService($intervals, $availability, new FreeTimeService($availability, $intervals));
        $statistics = new ProgressStatisticsService($context, $intervals);

        return [new ProgressTrendsService($statistics, $context), $statistics, $context];
    }

    private function activity(): array
    {
        return ['tasks' => [], 'subtasks' => [], 'study_sessions' => []];
    }

    private function completion(string $instant, ?string $deadline = null): array
    {
        return ['status' => 'completed', 'completed_at' => $instant, 'deadline_at' => $deadline];
    }

    public function test_empty_period_is_zero_filled_ordered_and_independent_of_server_timezone(): void
    {
        [$trends, $statistics] = $this->services();
        $zone = date_default_timezone_get();
        try {
            foreach (['UTC', 'Pacific/Auckland', 'America/New_York'] as $serverZone) {
                date_default_timezone_set($serverZone);
                $result = $trends->summarize($this->activity(), '2026-10-01', '2026-10-03', 'Europe/Kyiv');
                $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], array_column($result['days'], 'date'));
                $empty = $statistics->activityMetrics($this->activity(), []);
                $this->assertSame($empty, $result['totals']);
                foreach ($result['days'] as $day) {
                    $this->assertSame(['date' => $day['date'], ...$empty], $day);
                }
            }
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_completion_classifications_parent_child_separation_half_open_boundaries_and_totals(): void
    {
        [$trends, $statistics, $context] = $this->services();
        $activity = $this->activity();
        $activity['tasks'] = [
            $this->completion('2026-10-14 21:00:00', '2026-10-14 21:00:01'),
            $this->completion('2026-10-14 22:00:00', '2026-10-14 22:00:00'),
            $this->completion('2026-10-15 20:59:59', '2026-10-15 20:59:58'),
            $this->completion('2026-10-15 21:00:00'),
            $this->completion('2026-10-16 21:00:00'), // exclusive end
            ['status' => 'pending', 'completed_at' => null, 'deadline_at' => null],
            ['status' => 'pending', 'completed_at' => '2026-10-15 00:00:00', 'deadline_at' => null],
        ];
        $activity['subtasks'] = [$this->completion('2026-10-15 21:00:00')];
        $before = $activity;
        $result = $trends->summarize($activity, '2026-10-15', '2026-10-16', 'Europe/Kyiv');
        $this->assertSame(['total' => 3, 'on_time' => 2, 'late' => 1, 'without_deadline' => 0], $result['days'][0]['tasks_completed']);
        $this->assertSame(['total' => 1, 'on_time' => 0, 'late' => 0, 'without_deadline' => 1], $result['days'][1]['tasks_completed']);
        $this->assertSame(0, $result['days'][0]['subtasks_completed']['total']);
        $this->assertSame(1, $result['days'][1]['subtasks_completed']['without_deadline']);
        $this->assertSame($statistics->activityMetrics($activity, $context->dateIntervals('2026-10-15', '2026-10-16', 'Europe/Kyiv')), $result['totals']);
        $this->assertSame($before, $activity);
        $this->assertSame($result, $trends->summarize($activity, '2026-10-15', '2026-10-16', 'Europe/Kyiv'));
        $expected = $result;
        $result['days'][0]['tasks_completed']['total'] = 999;
        $this->assertSame($expected, $trends->summarize($activity, '2026-10-15', '2026-10-16', 'Europe/Kyiv'));
    }

    #[DataProvider('sessionStatuses')]
    public function test_start_attribution_full_duration_and_known_unknown_actual_time(string $status, ?int $actual): void
    {
        [$trends] = $this->services();
        $activity = $this->activity();
        $activity['study_sessions'] = [['status' => $status, 'starts_at' => '2026-10-15 23:59:59',
            'ends_at' => '2026-10-17 01:00:00', 'actual_minutes' => $actual]];
        $result = $trends->summarize($activity, '2026-10-15', '2026-10-16', 'UTC');
        $sessions = $result['days'][0]['study_sessions'];
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $key) {
            $this->assertSame(['count' => (int) ($status === $key), 'scheduled_seconds' => $status === $key ? 90001 : 0], $sessions[$key]);
        }
        $this->assertSame($status === 'completed' ? ($actual ?? 0) : 0, $sessions['completed_actual_minutes']);
        $this->assertSame((int) ($status === 'completed' && $actual !== null), $sessions['completed_with_actual_minutes']);
        $this->assertSame((int) ($status === 'completed' && $actual === null), $sessions['completed_without_actual_minutes']);
        $this->assertSame($sessions, $result['totals']['study_sessions']);
        $this->assertSame(0, $result['days'][1]['study_sessions'][$status]['count']);
    }

    public static function sessionStatuses(): array
    {
        return [['planned', null], ['completed', null], ['completed', 25], ['missed', null], ['rescheduled', null], ['cancelled', null]];
    }

    #[DataProvider('calendarCases')]
    public function test_exact_daily_membership_across_dst_skipped_repeated_and_disjoint_dates(string $zone, string $start, string $end, array $instants, array $counts): void
    {
        [$trends, $statistics, $context] = $this->services();
        $activity = $this->activity();
        foreach ($instants as $instant) {
            $activity['tasks'][] = $this->completion($instant);
            $activity['subtasks'][] = $this->completion($instant);
            $activity['study_sessions'][] = ['status' => 'planned', 'starts_at' => $instant,
                'ends_at' => gmdate('Y-m-d H:i:s', $context->utcSeconds($instant) + 61), 'actual_minutes' => null];
        }
        $result = $trends->summarize($activity, $start, $end, $zone);
        $this->assertSame($counts, array_column(array_column($result['days'], 'tasks_completed'), 'total'));
        $this->assertSame($counts, array_column(array_column($result['days'], 'subtasks_completed'), 'total'));
        $this->assertSame($counts, array_map(fn ($day) => $day['study_sessions']['planned']['count'], $result['days']));
        $this->assertSame($statistics->activityMetrics($activity, $context->dateIntervals($start, $end, $zone)), $result['totals']);
    }

    public static function calendarCases(): array
    {
        return [
            ['Europe/Kyiv', '2026-03-28', '2026-03-30', ['2026-03-28 21:59:59', '2026-03-28 22:00:00', '2026-03-29 00:59:59',
                '2026-03-29 01:00:00', '2026-03-29 20:59:59', '2026-03-29 21:00:00'], [1, 4, 1]],
            ['Europe/Kyiv', '2026-10-24', '2026-10-26', ['2026-10-24 20:59:59', '2026-10-24 21:00:00',
                '2026-10-25 00:30:00', '2026-10-25 01:30:00', '2026-10-25 21:59:59', '2026-10-25 22:00:00'], [1, 4, 1]],
            ['Pacific/Apia', '2011-12-29', '2011-12-31', ['2011-12-30 09:59:59', '2011-12-30 10:00:00'], [1, 0, 1]],
            ['America/Juneau', '1867-10-18', '1867-10-19', ['1867-10-17 12:00:00', '1867-10-18 10:00:00',
                '1867-10-19 01:00:00'], [2, 1]],
            ['America/Juneau', '1867-10-18', '1867-10-18', ['1867-10-17 12:00:00', '1867-10-18 10:00:00',
                '1867-10-19 01:00:00'], [2]],
            ['UTC', '2026-10-15', '2026-10-15', ['2026-10-14 23:59:59', '2026-10-15 00:00:00',
                '2026-10-15 23:59:59', '2026-10-16 00:00:00'], [2]],
        ];
    }

    public function test_exact_totals_reject_overflow_and_preserve_input(): void
    {
        [$trends] = $this->services();
        $empty = $trends->totals([]);
        $maximum = $empty;
        $maximum['study_sessions']['completed']['scheduled_seconds'] = PHP_INT_MAX;
        $maximum['tasks_completed']['total'] = PHP_INT_MAX;
        $this->assertSame($maximum, $trends->totals([$maximum, $empty]));
        $one = $empty;
        $one['study_sessions']['completed']['scheduled_seconds'] = 1;
        $before = [$maximum, $one];
        try {
            $trends->totals([$maximum, $one]);
            $this->fail('An overflowing total must fail.');
        } catch (UnexpectedValueException) {
            $this->assertSame($before, [$maximum, $one]);
        }
        $one = $empty;
        $one['tasks_completed']['total'] = 1;
        $this->expectException(UnexpectedValueException::class);
        $trends->totals([$maximum, $one]);
    }

    #[DataProvider('nonIntegers')]
    public function test_totals_never_coerce_invalid_metric_values(mixed $value): void
    {
        [$trends] = $this->services();
        $day = $trends->totals([]);
        $day['study_sessions']['completed_actual_minutes'] = $value;
        $this->expectException(UnexpectedValueException::class);
        $trends->totals([$day]);
    }

    public static function nonIntegers(): array
    {
        return [['1'], [1.0], [true], [null], [-1], [[]]];
    }

    #[DataProvider('invalidDates')]
    public function test_direct_pure_calculation_enforces_real_ordered_bounded_dates(string $start, string $end): void
    {
        [$trends] = $this->services();
        $this->expectException(InvalidArgumentException::class);
        $trends->summarize($this->activity(), $start, $end, 'UTC');
    }

    public static function invalidDates(): array
    {
        return [['2026-02-29', '2026-03-01'], ['2026-10-16', '2026-10-15'], ['2026-10-01', '2026-11-01']];
    }
}
