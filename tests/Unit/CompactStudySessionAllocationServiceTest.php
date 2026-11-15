<?php

namespace Tests\Unit;

use App\Services\Planning\PlanningConstraintService;
use App\Services\Planning\StudySessionAllocationService;
use App\Services\Planning\TimeIntervalService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CompactStudySessionAllocationServiceTest extends TestCase
{
    #[DataProvider('allocationCases')]
    public function test_exact_greedy_allocations_and_projected_constraints(array $work, array $context, int $cutoff, array $expected): void
    {
        $inputs = [$work, $context];
        $service = new StudySessionAllocationService;
        $result = $service->allocate($work, $context, $cutoff);
        $this->assertSame($expected, array_map(fn ($session): array => [$session['task_id'], $session['subtask_id'], $session['start'], $session['end']], $result['sessions']));
        $this->assertSame($result, $service->allocate($work, $context, $cutoff));
        $this->assertSame($inputs, [$work, $context]);
        $this->assertBatchValid($result, $context, $work, $cutoff);
    }

    public static function allocationCases(): array
    {
        return ['earliest slot input order normalized' => [[0 => ['task_id' => 1, 'subtask_id' => null, 'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => 90]]], ['range' => ['start' => 0, 'end' => 86400], 'timezone' => 'UTC', 'preferences' => ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], 'free_intervals' => [0 => ['start' => 200, 'end' => 300], 1 => ['start' => 10, 'end' => 100]], 'break_anchors' => [], 'workload_sessions' => []], 0, [0 => [0 => 1, 1 => null, 2 => 10, 3 => 100]]], 'stable work order preserved' => [[0 => ['task_id' => 9, 'subtask_id' => 4, 'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => 60]], 1 => ['task_id' => 2, 'subtask_id' => null, 'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => 60]]], ['range' => ['start' => 0, 'end' => 86400], 'timezone' => 'UTC', 'preferences' => ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], 'free_intervals' => [0 => ['start' => 0, 'end' => 120]], 'break_anchors' => [], 'workload_sessions' => []], 0, [0 => [0 => 9, 1 => 4, 2 => 0, 3 => 60], 1 => [0 => 2, 1 => null, 2 => 60, 3 => 120]]], 'overloaded day has no new capacity' => [[0 => ['task_id' => 1, 'subtask_id' => null, 'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => 60]]], ['range' => ['start' => 0, 'end' => 86400], 'timezone' => 'UTC', 'preferences' => ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => null], 'free_intervals' => [0 => ['start' => 200, 'end' => 500]], 'break_anchors' => [], 'workload_sessions' => [0 => ['start' => 0, 'end' => 61]]], 0, []], 'weekly limit cuts work' => [[0 => ['task_id' => 1, 'subtask_id' => null, 'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => 120]]], ['range' => ['start' => 0, 'end' => 86400], 'timezone' => 'UTC', 'preferences' => ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => 2], 'free_intervals' => [0 => ['start' => 200, 'end' => 500]], 'break_anchors' => [], 'workload_sessions' => [0 => ['start' => 0, 'end' => 60]]], 0, [0 => [0 => 1, 1 => null, 2 => 200, 3 => 260]]]];
    }

    #[DataProvider('calendarCases')]
    public function test_workload_calendar_boundaries_and_later_feasible_starts(string $zone, string $start, string $end, int $effort, array $preferences, array $sources, array $expected): void
    {
        $start = self::ts($start);
        $end = self::ts($end);
        $context = self::context([[$start, $end]], $preferences, [], array_map(fn ($pair): array => [self::ts($pair[0]), self::ts($pair[1])], $sources));
        $context['timezone'] = $zone;
        $context['range'] = ['start' => $start, 'end' => $end];
        $work = [self::work(1, $effort)];
        $result = (new StudySessionAllocationService)->allocate($work, $context, $start);
        $this->assertSame(array_map(fn ($pair): array => [self::ts($pair[0]), self::ts($pair[1])], $expected), array_map(fn ($session): array => [$session['start'], $session['end']], $result['sessions']));
        $this->assertBatchValid($result, $context, $work, $start);
    }

    public static function calendarCases(): array
    {
        return ['later same interval spreads minimum over midnight' => ['UTC', '2026-10-12T23:00:00Z', '2026-10-13T01:00:00Z', 1800, ['min_session_minutes' => 30, 'max_daily_study_minutes' => 60], [0 => [0 => '2026-10-12T08:00:00Z', 1 => '2026-10-12T08:50:00Z']], [0 => [0 => '2026-10-12T23:50:00Z', 1 => '2026-10-13T00:20:00Z']]], 'feasible pocket even both region endpoints fail' => ['UTC', '2026-10-12T23:00:00Z', '2026-10-13T01:00:00Z', 1800, ['min_session_minutes' => 30, 'max_daily_study_minutes' => 60], [0 => [0 => '2026-10-12T08:00:00Z', 1 => '2026-10-12T08:45:00Z'], 1 => [0 => '2026-10-13T08:00:00Z', 1 => '2026-10-13T08:45:00Z']], [0 => [0 => '2026-10-12T23:45:00Z', 1 => '2026-10-13T00:15:00Z']]], 'ISO year label includes prior calendar year' => ['UTC', '2027-01-01T10:00:00Z', '2027-01-01T12:00:00Z', 3600, ['max_weekly_study_minutes' => 90], [0 => [0 => '2026-12-28T08:00:00Z', 1 => '2026-12-28T09:00:00Z']], [0 => [0 => '2027-01-01T10:00:00Z', 1 => '2027-01-01T10:30:00Z']]]];
    }

    public function test_reason_codes_are_evidence_and_exact_remaining_effort_is_retained(): void
    {
        $result = (new StudySessionAllocationService)->allocate([self::work(1, 120)], self::context([[0, 300]], ['max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => 1], [], [[0, 60]]), 0);
        $this->assertSame([['task_id' => 1, 'subtask_id' => null, 'requested_seconds' => 120, 'allocated_seconds' => 0, 'unscheduled_seconds' => 120, 'reason_codes' => ['daily_study_limit_exceeded', 'weekly_study_limit_exceeded', 'planning_horizon_exhausted']]], $result['work_results']);
        $this->assertSame(['requested_seconds' => 120, 'allocated_seconds' => 0, 'unscheduled_seconds' => 120, 'fully_allocated' => false], $result['summary']);
    }

    #[DataProvider('residualBudgets')]
    public function test_event_boundary_search_matches_an_independent_small_exhaustive_c8_oracle(int $before, int $after): void
    {
        $start = self::ts('2026-10-12T23:59:00Z');
        $end = self::ts('2026-10-13T00:02:00Z');
        $sources = [];
        foreach ([['2026-10-12T08:00:00Z', $before], ['2026-10-13T08:00:00Z', $after]] as [$time, $budget]) {
            if ($budget < 60) {
                $sources[] = [self::ts($time), self::ts($time) + 60 - $budget];
            }
        }
        $context = self::context([[$start, $end]], ['min_session_minutes' => 1, 'max_session_minutes' => 1, 'max_daily_study_minutes' => 1], [], $sources);
        $context['range'] = ['start' => $start, 'end' => $end];
        $expected = [];
        $constraints = new PlanningConstraintService;
        for ($point = $start; $point + 60 <= $end; $point++) {
            if ($constraints->evaluate(['start' => $point, 'end' => $point + 60], $context['free_intervals'], [], $context['workload_sessions'], 'UTC', $context['preferences']) === []) {
                $expected = [[$point, $point + 60]];
                break;
            }
        }
        $result = (new StudySessionAllocationService)->allocate([self::work(1, 60)], $context, $start);
        $this->assertSame($expected, array_map(fn ($session): array => [$session['start'], $session['end']], $result['sessions']));
    }

    public static function residualBudgets(): array
    {
        return [0 => [0, 0], 1 => [0, 15], 15 => [60, 60]];
    }

    public function test_final_tiny_remainder_is_reported_without_an_undersized_session(): void
    {
        $result = (new StudySessionAllocationService)->allocate([self::work(1, 61)], self::context([[0, 300]], ['min_session_minutes' => 1]), 0);
        $this->assertSame(61, $result['sessions'][0]['duration_seconds']);
        $short = (new StudySessionAllocationService)->allocate([self::work(1, 61)], self::context([[0, 60]], ['min_session_minutes' => 1]), 0);
        $this->assertSame(1, $short['work_results'][0]['unscheduled_seconds']);
        $this->assertContains('below_minimum_session_duration', $short['work_results'][0]['reason_codes']);
    }

    public function test_subminimum_work_reports_only_duration_even_when_other_constraints_are_exhausted(): void
    {
        $context = self::context([[0, 300]], ['min_session_minutes' => 1, 'max_daily_study_minutes' => 1], [], [[0, 60]]);
        $result = (new StudySessionAllocationService)->allocate([self::work(1, 59, 30)], $context, 0);
        $this->assertSame(['below_minimum_session_duration'], $result['work_results'][0]['reason_codes']);
        $this->assertSame(59, $result['summary']['unscheduled_seconds']);
    }

    #[DataProvider('largestDurationBudgets')]
    public function test_earliest_start_and_largest_duration_match_exhaustive_oracle_at_day_and_week_boundary(int $before, int $after, bool $weekly): void
    {
        $start = self::ts('2026-10-11T23:59:00Z');
        $end = self::ts('2026-10-12T00:02:00Z');
        $sources = [];
        foreach ([['2026-10-11T08:00:00Z', $before], ['2026-10-12T08:00:00Z', $after]] as [$time, $capacity]) {
            if ($capacity < 60) {
                $sources[] = [self::ts($time), self::ts($time) + 60 - $capacity];
            }
        }
        $context = self::context([[$start, $end]], ['min_session_minutes' => 1, 'max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => $weekly ? 1 : null], [], $sources);
        $context['range'] = ['start' => $start, 'end' => $end];
        $expected = null;
        $constraints = new PlanningConstraintService;
        for ($point = $start; $point + 60 <= $end; $point++) {
            for ($seconds = min(120, $end - $point); $seconds >= 60; $seconds--) {
                $candidate = ['start' => $point, 'end' => $point + $seconds];
                if ($constraints->evaluate($candidate, $context['free_intervals'], [], $context['workload_sessions'], 'UTC', $context['preferences']) === []) {
                    $expected = $candidate;
                    break 2;
                }
            }
        }
        $result = (new StudySessionAllocationService)->allocate([self::work(1, 120)], $context, $start);
        $actual = $result['sessions'][0] ?? null;
        $this->assertSame($expected, $actual === null ? null : ['start' => $actual['start'], 'end' => $actual['end']]);
        $this->assertBatchValid($result, $context, [self::work(1, 120)], $start);
    }

    public static function largestDurationBudgets(): array
    {
        return [0 => [0, 0, false], 1 => [0, 0, true], 31 => [60, 60, true]];
    }

    public function test_difficult_boundary_search_has_no_per_second_constraint_probing(): void
    {
        $context = self::context([[0, 2678400]], ['min_session_minutes' => 30, 'max_daily_study_minutes' => 60]);
        $context['range'] = ['start' => 0, 'end' => 2678400];
        $context['workload_sessions'] = [['start' => 0, 'end' => 600]];
        $constraints = $this->createMock(PlanningConstraintService::class);
        $constraints->expects($this->exactly(2))->method('evaluate')->willReturn([]);
        $result = (new StudySessionAllocationService(new TimeIntervalService, $constraints))->allocate([self::work(1, 1800)], $context, 0);
        $this->assertSame(1800, $result['summary']['allocated_seconds']);
    }

    #[DataProvider('invalidCases')]
    public function test_malformed_inputs_and_overflow_fail_explicitly(array $work, array $context, mixed $cutoff): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudySessionAllocationService)->allocate($work, $context, $cutoff);
    }

    public static function invalidCases(): array
    {
        return [0 => [[0 => ['task_id' => 1, 'subtask_id' => null, 'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => 60]]], ['range' => ['start' => 0, 'end' => 86400], 'timezone' => 'UTC', 'preferences' => ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], 'free_intervals' => [0 => ['start' => 0, 'end' => 60]], 'break_anchors' => [], 'workload_sessions' => []], '0'], 1 => [[0 => ['task_id' => 1, 'subtask_id' => null, 'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => 60]]], ['range' => ['start' => 0, 'end' => 86400], 'timezone' => 'UTC', 'preferences' => ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], 'free_intervals' => [0 => ['start' => 0, 'end' => 60]], 'break_anchors' => [], 'workload_sessions' => []], 0.0], 15 => [[0 => ['task_id' => 1, 'subtask_id' => null, 'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => 60]]], ['range' => ['start' => 0, 'end' => 86400], 'timezone' => 'UTC', 'preferences' => ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => '1', 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], 'free_intervals' => [0 => ['start' => 0, 'end' => 60]], 'break_anchors' => [], 'workload_sessions' => []], 0]];
    }

    private static function work(int $id, int $seconds, ?int $deadline = null, ?int $child = null): array
    {
        return ['task_id' => $id, 'subtask_id' => $child, 'effective_deadline_utc' => $deadline, 'effort' => ['unallocated_work_seconds' => $seconds]];
    }

    private static function context(array $free, array $preferences = [], array $anchors = [], array $workload = []): array
    {
        $interval = fn ($pair): array => ['start' => $pair[0], 'end' => $pair[1]];

        return ['range' => ['start' => 0, 'end' => 86400], 'timezone' => 'UTC', 'preferences' => array_replace(array_fill_keys(['min_session_minutes', 'max_session_minutes', 'preferred_break_minutes', 'max_daily_study_minutes', 'max_weekly_study_minutes'], null), $preferences), 'free_intervals' => array_map($interval, $free), 'break_anchors' => array_map($interval, $anchors), 'workload_sessions' => array_map($interval, $workload)];
    }

    private static function ts(string $value): int
    {
        return (new DateTimeImmutable($value))->getTimestamp();
    }

    private function assertBatchValid(array $result, array $context, array $work, int $cutoff): void
    {
        $constraints = new PlanningConstraintService;
        $attribution = [];
        foreach ($work as $item) {
            $attribution[$item['task_id'].':'.($item['subtask_id'] ?? 'task')] = $item;
        }
        foreach ($result['sessions'] as $index => $session) {
            $candidate = ['start' => $session['start'], 'end' => $session['end']];
            $this->assertSame($session['duration_seconds'], $session['end'] - $session['start']);
            $this->assertGreaterThanOrEqual($cutoff, $session['start']);
            $others = array_map(fn ($other): array => ['start' => $other['start'], 'end' => $other['end']], array_values(array_filter($result['sessions'], fn ($other, $key): bool => $key !== $index, ARRAY_FILTER_USE_BOTH)));
            $item = $attribution[$session['task_id'].':'.($session['subtask_id'] ?? 'task')];
            $this->assertSame([], $constraints->evaluate($candidate, $context['free_intervals'], [...$context['break_anchors'], ...$others], [...$context['workload_sessions'], ...$others], $context['timezone'], $context['preferences'], $item['effective_deadline_utc']));
        }
        foreach ($result['work_results'] as $item) {
            $this->assertSame($item['requested_seconds'], $item['allocated_seconds'] + $item['unscheduled_seconds']);
        }
        $this->assertSame($result['summary']['allocated_seconds'], array_sum(array_column($result['sessions'], 'duration_seconds')));
    }
}
