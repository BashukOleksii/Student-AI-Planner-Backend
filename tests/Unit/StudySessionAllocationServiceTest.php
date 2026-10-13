<?php

namespace Tests\Unit;

use App\Services\Planning\PlanningConstraintService;
use App\Services\Planning\StudySessionAllocationService;
use App\Services\Planning\TimeIntervalService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudySessionAllocationServiceTest extends TestCase
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
        $w = self::work(...);
        $c = self::context(...);

        return [
            'earliest slot input order normalized' => [[$w(1, 90)], $c([[200, 300], [10, 100]]), 0, [[1, null, 10, 100]]],
            'stable work order preserved' => [[$w(9, 60, null, 4), $w(2, 60)], $c([[0, 120]]), 0, [[9, 4, 0, 60], [2, null, 60, 120]]],
            'largest duration with null bounds' => [[$w(1, 181)], $c([[0, 300]]), 0, [[1, null, 0, 181]]],
            'maximum splits exact seconds' => [[$w(1, 181)], $c([[0, 300]], ['max_session_minutes' => 1]), 0, [[1, null, 0, 60], [1, null, 60, 120], [1, null, 120, 180], [1, null, 180, 181]]],
            'minimum excludes tiny work' => [[$w(1, 59)], $c([[0, 300]], ['min_session_minutes' => 1]), 0, []],
            'minimum equality' => [[$w(1, 60)], $c([[0, 60]], ['min_session_minutes' => 1]), 0, [[1, null, 0, 60]]],
            'tiny first slot later usable' => [[$w(1, 60)], $c([[0, 59], [100, 160]], ['min_session_minutes' => 1]), 0, [[1, null, 100, 160]]],
            'null minimum one second is valid' => [[$w(1, 1)], $c([[0, 1]]), 0, [[1, null, 0, 1]]],
            'breaks same work' => [[$w(1, 120)], $c([[0, 200]], ['max_session_minutes' => 1, 'preferred_break_minutes' => 1]), 0, [[1, null, 0, 60], [1, null, 120, 180]]],
            'breaks different work' => [[$w(1, 60), $w(2, 60)], $c([[0, 200]], ['preferred_break_minutes' => 1]), 0, [[1, null, 0, 60], [2, null, 120, 180]]],
            'anchor break before exact' => [[$w(1, 60)], $c([[0, 300]], ['preferred_break_minutes' => 1], [[0, 100]]), 0, [[1, null, 160, 220]]],
            'following anchor break exact' => [[$w(1, 60)], $c([[0, 300]], ['preferred_break_minutes' => 1], [[120, 180]]), 0, [[1, null, 0, 60]]],
            'one second short forces later interval' => [[$w(1, 60)], $c([[0, 300]], ['min_session_minutes' => 1, 'preferred_break_minutes' => 1], [[119, 180]]), 0, [[1, null, 240, 300]]],
            'null break still prevents overlap' => [[$w(1, 120)], $c([[0, 300]], [], [[0, 100]]), 0, [[1, null, 100, 220]]],
            'deadline cuts through slot equality' => [[$w(1, 120, 75)], $c([[0, 300]]), 0, [[1, null, 0, 75]]],
            'deadline before requested capacity' => [[$w(1, 60, 50)], $c([[100, 300]]), 0, []],
            'unplaced earlier work does not stop next work' => [[$w(1, 120, 50), $w(2, 60)], $c([[0, 300]], ['min_session_minutes' => 1]), 0, [[2, null, 0, 60]]],
            'different subtasks share projected breaks' => [[$w(1, 60, null, 7), $w(1, 60, null, 8)], $c([[0, 300]], ['preferred_break_minutes' => 1]), 0, [[1, 7, 0, 60], [1, 8, 120, 180]]],
            'cutoff excludes past time' => [[$w(1, 120)], $c([[0, 300]]), 150, [[1, null, 150, 270]]],
            'horizon entirely past' => [[$w(1, 60)], $c([[0, 300]]), 400, []],
            'no capacity' => [[$w(1, 60)], $c([]), 0, []],
            'empty work' => [[], $c([[0, 300]]), 0, []],
            'skipped local date' => [[$w(1, 60)], ['range' => null, ...array_diff_key($c([]), ['range' => true])], 0, []],
            'short tail balanced with valid second placement' => [[$w(1, 5400)], $c([[0, 7200]], ['min_session_minutes' => 40, 'max_session_minutes' => 60]), 0, [[1, null, 0, 3000], [1, null, 3000, 5400]]],
            'short tail cannot be placed do not reduce first' => [[$w(1, 5400)], $c([[0, 3600]], ['min_session_minutes' => 40, 'max_session_minutes' => 60]), 0, [[1, null, 0, 3600]]],
            'short tail balanced including break' => [[$w(1, 5400)], $c([[0, 6000]], ['min_session_minutes' => 40, 'max_session_minutes' => 60, 'preferred_break_minutes' => 10]), 0, [[1, null, 0, 3000], [1, null, 3600, 6000]]],
            'projected daily workload cuts next work' => [[$w(1, 60), $w(2, 120)], $c([[0, 300]], ['max_daily_study_minutes' => 2]), 0, [[1, null, 0, 60], [2, null, 60, 120]]],
            'persisted equal intervals stay additive' => [[$w(1, 120)], $c([[200, 500]], ['max_daily_study_minutes' => 3], [], [[0, 60], [0, 60]]), 0, [[1, null, 200, 260]]],
            'overloaded day has no new capacity' => [[$w(1, 60)], $c([[200, 500]], ['max_daily_study_minutes' => 1], [], [[0, 61]]), 0, []],
            'weekly limit cuts work' => [[$w(1, 120)], $c([[200, 500]], ['max_weekly_study_minutes' => 2], [], [[0, 60]]), 0, [[1, null, 200, 260]]],
        ];
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
        $this->assertSame(array_map(fn ($pair): array => [self::ts($pair[0]), self::ts($pair[1])], $expected),
            array_map(fn ($session): array => [$session['start'], $session['end']], $result['sessions']));
        $this->assertBatchValid($result, $context, $work, $start);
    }

    public static function calendarCases(): array
    {
        return [
            'later same interval spreads minimum over midnight' => ['UTC', '2026-10-12T23:00:00Z', '2026-10-13T01:00:00Z', 1800,
                ['min_session_minutes' => 30, 'max_daily_study_minutes' => 60], [['2026-10-12T08:00:00Z', '2026-10-12T08:50:00Z']],
                [['2026-10-12T23:50:00Z', '2026-10-13T00:20:00Z']]],
            'feasible pocket even both region endpoints fail' => ['UTC', '2026-10-12T23:00:00Z', '2026-10-13T01:00:00Z', 1800,
                ['min_session_minutes' => 30, 'max_daily_study_minutes' => 60], [
                    ['2026-10-12T08:00:00Z', '2026-10-12T08:45:00Z'], ['2026-10-13T08:00:00Z', '2026-10-13T08:45:00Z']],
                [['2026-10-12T23:45:00Z', '2026-10-13T00:15:00Z']]],
            'day reset without fixed seconds' => ['Europe/Kyiv', '2026-03-29T20:00:00Z', '2026-03-30T01:00:00Z', 7200,
                ['max_daily_study_minutes' => 60], [], [['2026-03-29T20:00:00Z', '2026-03-29T22:00:00Z']]],
            'fall fold scheduled real elapsed' => ['Europe/Kyiv', '2026-10-25T00:00:00Z', '2026-10-25T03:00:00Z', 10800,
                ['max_daily_study_minutes' => 120], [], [['2026-10-25T00:00:00Z', '2026-10-25T02:00:00Z']]],
            'spring gap real elapsed' => ['Europe/Kyiv', '2026-03-29T00:00:00Z', '2026-03-29T03:00:00Z', 10800,
                ['max_daily_study_minutes' => 120], [], [['2026-03-29T00:00:00Z', '2026-03-29T02:00:00Z']]],
            'ISO week resets across Sunday' => ['UTC', '2026-10-11T23:00:00Z', '2026-10-12T01:00:00Z', 7200,
                ['max_weekly_study_minutes' => 60], [], [['2026-10-11T23:00:00Z', '2026-10-12T01:00:00Z']]],
            'exhausted prior ISO week next boundary' => ['UTC', '2026-10-11T23:00:00Z', '2026-10-12T01:00:00Z', 3600,
                ['max_weekly_study_minutes' => 60], [['2026-10-05T08:00:00Z', '2026-10-05T09:00:00Z']],
                [['2026-10-12T00:00:00Z', '2026-10-12T01:00:00Z']]],
            'ISO year label includes prior calendar year' => ['UTC', '2027-01-01T10:00:00Z', '2027-01-01T12:00:00Z', 3600,
                ['max_weekly_study_minutes' => 90], [['2026-12-28T08:00:00Z', '2026-12-28T09:00:00Z']],
                [['2027-01-01T10:00:00Z', '2027-01-01T10:30:00Z']]],
        ];
    }

    public function test_reason_codes_are_evidence_and_exact_remaining_effort_is_retained(): void
    {
        $result = (new StudySessionAllocationService)->allocate([self::work(1, 120)], self::context([[0, 300]],
            ['max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => 1], [], [[0, 60]]), 0);
        $this->assertSame([['task_id' => 1, 'subtask_id' => null, 'requested_seconds' => 120, 'allocated_seconds' => 0,
            'unscheduled_seconds' => 120, 'reason_codes' => ['daily_study_limit_exceeded', 'weekly_study_limit_exceeded', 'planning_horizon_exhausted']]], $result['work_results']);
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
        // Exhaustive seconds are intentionally confined to this 180-second test oracle.
        for ($point = $start; $point + 60 <= $end; $point++) {
            if ($constraints->evaluate(['start' => $point, 'end' => $point + 60], $context['free_intervals'], [],
                $context['workload_sessions'], 'UTC', $context['preferences']) === []) {
                $expected = [[$point, $point + 60]];
                break;
            }
        }
        $result = (new StudySessionAllocationService)->allocate([self::work(1, 60)], $context, $start);
        $this->assertSame($expected, array_map(fn ($session): array => [$session['start'], $session['end']], $result['sessions']));
    }

    public static function residualBudgets(): array
    {
        $cases = [];
        foreach ([0, 15, 30, 60] as $before) {
            foreach ([0, 15, 30, 60] as $after) {
                $cases[] = [$before, $after];
            }
        }

        return $cases;
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
        // Sunday → Monday exercises both local-day and ISO-week capacity changes.
        $start = self::ts('2026-10-11T23:59:00Z');
        $end = self::ts('2026-10-12T00:02:00Z');
        $sources = [];
        foreach ([['2026-10-11T08:00:00Z', $before], ['2026-10-12T08:00:00Z', $after]] as [$time, $capacity]) {
            if ($capacity < 60) {
                $sources[] = [self::ts($time), self::ts($time) + 60 - $capacity];
            }
        }
        $context = self::context([[$start, $end]], ['min_session_minutes' => 1,
            'max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => $weekly ? 1 : null], [], $sources);
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
        $cases = [];
        foreach ([0, 17, 31, 60] as $before) {
            foreach ([0, 17, 31, 60] as $after) {
                foreach ([false, true] as $weekly) {
                    $cases[] = [$before, $after, $weekly];
                }
            }
        }

        return $cases;
    }

    public function test_difficult_boundary_search_has_no_per_second_constraint_probing(): void
    {
        $context = self::context([[0, 2678400]], ['min_session_minutes' => 30, 'max_daily_study_minutes' => 60]);
        $context['range'] = ['start' => 0, 'end' => 2678400];
        $context['workload_sessions'] = [['start' => 0, 'end' => 600]];
        $constraints = $this->createMock(PlanningConstraintService::class);
        // One input-validation call and one accepted-candidate validation; no second-by-second oracle search.
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
        $context = self::context([[0, 60]]);
        $work = self::work(1, 60);

        return [
            [[$work], $context, '0'], [[$work], $context, 0.0], [[$work], $context, false],
            [['item' => $work], $context, 0], [[self::work(1, 0)], $context, 0],
            [[array_replace($work, ['task_id' => '1'])], $context, 0],
            [[array_replace($work, ['subtask_id' => true])], $context, 0],
            [[array_replace($work, ['effective_deadline_utc' => '60'])], $context, 0],
            [[$work, $work], $context, 0], [[self::work(1, PHP_INT_MAX), self::work(2, 1)], $context, 0],
            [[$work], [], 0], [[$work], array_replace($context, ['range' => ['start' => 0, 'end' => 0]]), 0],
            [[$work], array_replace($context, ['free_intervals' => [['start' => 0, 'end' => 86401]]]), 0],
            [[$work], array_replace($context, ['preferences' => ['min_session_minutes' => 1]]), 0],
            [[$work], self::context([[0, 60]], ['min_session_minutes' => 2, 'max_session_minutes' => 1]), 0],
            [[$work], self::context([[0, 60]], ['preferred_break_minutes' => '1']), 0],
        ];
    }

    private static function work(int $id, int $seconds, ?int $deadline = null, ?int $child = null): array
    {
        return ['task_id' => $id, 'subtask_id' => $child, 'effective_deadline_utc' => $deadline, 'effort' => ['unallocated_work_seconds' => $seconds]];
    }

    private static function context(array $free, array $preferences = [], array $anchors = [], array $workload = []): array
    {
        $interval = fn ($pair): array => ['start' => $pair[0], 'end' => $pair[1]];

        return ['range' => ['start' => 0, 'end' => 86400], 'timezone' => 'UTC', 'preferences' => array_replace(array_fill_keys([
            'min_session_minutes', 'max_session_minutes', 'preferred_break_minutes', 'max_daily_study_minutes', 'max_weekly_study_minutes',
        ], null), $preferences), 'free_intervals' => array_map($interval, $free), 'break_anchors' => array_map($interval, $anchors),
            'workload_sessions' => array_map($interval, $workload)];
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
            // Revalidate against the complete batch, excluding only the candidate itself.
            $this->assertSame([], $constraints->evaluate($candidate, $context['free_intervals'],
                [...$context['break_anchors'], ...$others], [...$context['workload_sessions'], ...$others],
                $context['timezone'], $context['preferences'], $item['effective_deadline_utc']));
        }
        foreach ($result['work_results'] as $item) {
            $this->assertSame($item['requested_seconds'], $item['allocated_seconds'] + $item['unscheduled_seconds']);
        }
        $this->assertSame($result['summary']['allocated_seconds'], array_sum(array_column($result['sessions'], 'duration_seconds')));
    }
}
