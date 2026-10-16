<?php

namespace Tests\Unit;

use App\Services\Planning\AvailabilityOccurrenceService;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\TimeIntervalService;
use App\Services\Progress\ProgressComparisonService;
use App\Services\Progress\ProgressStatisticsService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class ProgressComparisonServiceTest extends TestCase
{
    private function services(): array
    {
        $intervals = new TimeIntervalService;
        $availability = new AvailabilityOccurrenceService($intervals);
        $context = new PlanningContextService($intervals, $availability, new FreeTimeService($availability, $intervals));
        $statistics = new ProgressStatisticsService($context, $intervals);

        return [new ProgressComparisonService($statistics, $context, $intervals), $statistics, $context];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_period_pairs_are_rejected(array $dates): void
    {
        [$comparison] = $this->services();
        $this->expectException(InvalidArgumentException::class);
        $comparison->validatePeriods(...$dates);
    }

    public static function invalidPeriods(): array
    {
        return [
            [['2026-09-01', '2026-09-07', '2026-10-01', '2026-10-08']],
            [['2026-09-01', '2026-09-07', '2026-09-07', '2026-09-13']],
            [['2026-10-01', '2026-10-07', '2026-09-01', '2026-09-07']],
            [['2026-09-07', '2026-09-01', '2026-10-01', '2026-10-07']],
            [['2026-09-01', '2026-09-07', '2026-10-07', '2026-10-01']],
            [['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01']],
            [['2026-02-29', '2026-03-01', '2026-10-01', '2026-10-02']],
        ];
    }

    public function test_valid_year_over_year_equal_nominal_periods_and_storage_edges(): void
    {
        [$comparison] = $this->services();
        foreach ([['2025-10-01', '2025-10-31', '2026-10-01', '2026-10-31'],
            ['0001-01-01', '0001-01-01', '9999-12-31', '9999-12-31'],
            ['2026-03-29', '2026-03-29', '2026-10-25', '2026-10-25']] as $dates) {
            $comparison->validatePeriods(...$dates);
        }
        $this->addToAssertionCount(3);
    }

    public function test_every_delta_leaf_is_exact_signed_ordered_and_inputs_are_immutable(): void
    {
        [$comparison, $statistics] = $this->services();
        $empty = $statistics->activityMetrics(['tasks' => [], 'subtasks' => [], 'study_sessions' => []], []);
        $fill = function (array $shape, int $value) use (&$fill): array {
            return array_map(fn ($item) => is_array($item) ? $fill($item, $value) : $value, $shape);
        };
        $maximum = $fill($empty, PHP_INT_MAX);
        $before = [$maximum, $empty];
        $this->assertSame($maximum, $comparison->delta($empty, $maximum));
        $this->assertSame($fill($empty, -PHP_INT_MAX), $comparison->delta($maximum, $empty));
        $this->assertSame($empty, $comparison->delta($maximum, $maximum));
        $this->assertSame($empty, $comparison->delta($empty, $empty));
        $this->assertSame($before, [$maximum, $empty]);
        $this->assertSame($maximum, $comparison->delta(array_reverse($empty, true), array_reverse($maximum, true)));
    }

    #[DataProvider('invalidLeaves')]
    public function test_overflowed_or_noninteger_operands_are_rejected_on_either_side(mixed $value, bool $baseline): void
    {
        [$comparison, $statistics] = $this->services();
        $empty = $statistics->activityMetrics(['tasks' => [], 'subtasks' => [], 'study_sessions' => []], []);
        $bad = $empty;
        $bad['study_sessions']['completed_actual_minutes'] = $value;
        $this->expectException(UnexpectedValueException::class);
        $comparison->delta($baseline ? $bad : $empty, $baseline ? $empty : $bad);
    }

    public static function invalidLeaves(): iterable
    {
        foreach ([PHP_INT_MAX + 1, PHP_INT_MIN, -1, '1', 1.0, true, null, []] as $value) {
            yield [$value, true];
            yield [$value, false];
        }
    }

    public function test_missing_or_extra_metric_keys_fail_closed(): void
    {
        [$comparison, $statistics] = $this->services();
        $empty = $statistics->activityMetrics(['tasks' => [], 'subtasks' => [], 'study_sessions' => []], []);
        foreach ([['extra' => 0, ...$empty], array_diff_key($empty, ['tasks_completed' => 0])] as $bad) {
            try {
                $comparison->delta($bad, $empty);
                $this->fail('Invalid metric shape must fail.');
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_independent_work_all_statuses_unknown_actuals_and_factual_directions(): void
    {
        [$comparison, $statistics, $context] = $this->services();
        $activity = ['tasks' => [], 'subtasks' => [], 'study_sessions' => []];
        foreach (['2026-09-01', '2026-10-01'] as $index => $date) {
            $completion = ['status' => 'completed', 'completed_at' => $date.' 12:00:00', 'deadline_at' => $date.' 11:00:00'];
            $activity['tasks'] = [...$activity['tasks'], ...array_fill(0, $index + 1, $completion)];
            $activity['subtasks'] = [...$activity['subtasks'], ...array_fill(0, 2 - $index, [...$completion, 'deadline_at' => null])];
            foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
                $activity['study_sessions'][] = ['status' => $status, 'starts_at' => $date.' 23:59:59',
                    'ends_at' => substr($date, 0, 8).'02 01:00:00', 'actual_minutes' => $status === 'completed' && $index === 1 ? 25 : null];
            }
            // Pending work is not a reconstructed historical completion even with a stale timestamp.
            $activity['tasks'][] = [...$completion, 'status' => 'pending'];
        }
        $before = $activity;
        $baseline = $statistics->activityMetrics($activity, $context->dateIntervals('2026-09-01', '2026-09-01', 'UTC'));
        $current = $statistics->activityMetrics($activity, $context->dateIntervals('2026-10-01', '2026-10-01', 'UTC'));
        $result = $comparison->compare($baseline, $current);
        $this->assertSame(1, $result['delta']['tasks_completed']['total']);
        $this->assertSame(-1, $result['delta']['subtasks_completed']['total']);
        $this->assertSame(['task_completions' => 'increased', 'subtask_completions' => 'decreased',
            'missed_sessions' => 'unchanged', 'known_actual_minutes' => 'increased',
            'baseline_has_unknown_actual_minutes' => true, 'current_has_unknown_actual_minutes' => false], $result['observations']);
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
            $this->assertSame(['count' => 1, 'scheduled_seconds' => 3601], $baseline['study_sessions'][$status]);
            $this->assertSame(['count' => 0, 'scheduled_seconds' => 0], $result['delta']['study_sessions'][$status]);
        }
        $this->assertSame(0, $baseline['study_sessions']['completed_actual_minutes']);
        $this->assertSame(25, $current['study_sessions']['completed_actual_minutes']);
        $this->assertSame($before, $activity);
        $reverse = $comparison->compare($current, $baseline);
        $this->assertSame('decreased', $reverse['observations']['task_completions']);
        $this->assertSame('increased', $reverse['observations']['subtask_completions']);
        $this->assertSame('decreased', $reverse['observations']['known_actual_minutes']);
        $this->assertSame('unchanged', $comparison->compare($current, $current)['observations']['task_completions']);
        $moreMissed = $current;
        $moreMissed['study_sessions']['missed']['count']++;
        $this->assertSame('increased', $comparison->compare($current, $moreMissed)['observations']['missed_sessions']);
        $this->assertSame('decreased', $comparison->compare($moreMissed, $current)['observations']['missed_sessions']);
    }

    #[DataProvider('calendarCases')]
    public function test_comparison_uses_exact_dst_and_historical_date_membership(string $zone, string $baseline, string $current, array $instants, array $expected): void
    {
        [$comparison, $statistics, $context] = $this->services();
        $activity = ['tasks' => array_map(fn ($instant) => ['status' => 'completed', 'completed_at' => $instant, 'deadline_at' => null], $instants),
            'subtasks' => [], 'study_sessions' => []];
        $left = $statistics->activityMetrics($activity, $context->dateIntervals($baseline, $baseline, $zone));
        $right = $statistics->activityMetrics($activity, $context->dateIntervals($current, $current, $zone));
        $this->assertSame($expected, [$left['tasks_completed']['total'], $right['tasks_completed']['total']]);
        $this->assertSame($expected[1] - $expected[0], $comparison->delta($left, $right)['tasks_completed']['total']);
    }

    public static function calendarCases(): array
    {
        return [
            ['Europe/Kyiv', '2026-03-29', '2026-10-25', ['2026-03-28 22:00:00', '2026-03-29 01:00:00',
                '2026-03-29 21:00:00', '2026-10-25 00:30:00', '2026-10-25 01:30:00', '2026-10-25 22:00:00'], [2, 2]],
            ['Pacific/Apia', '2011-12-30', '2011-12-31', ['2011-12-30 09:59:59', '2011-12-30 10:00:00'], [0, 1]],
            ['America/Juneau', '1867-10-18', '1867-10-19', ['1867-10-17 12:00:00', '1867-10-18 10:00:00', '1867-10-19 01:00:00'], [2, 1]],
        ];
    }
}
