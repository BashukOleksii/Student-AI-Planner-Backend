<?php

namespace Tests\Unit;

use App\Services\Planning\BreakConstraintService;
use App\Services\Planning\PlanningConstraintService;
use App\Services\Planning\SessionDurationConstraintService;
use App\Services\Planning\TimeIntervalService;
use App\Services\Planning\WorkloadConstraintService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlanningConstraintServiceTest extends TestCase
{
    #[DataProvider('candidateCases')]
    public function test_combined_evaluation_returns_all_violations_in_stable_order_without_mutation(
        array $candidate, array $free, array $anchors, array $sessions, string $timezone, array $preferences, ?int $deadline, array $expected,
    ): void {
        $original = [$candidate, $free, $anchors, $sessions, $preferences];
        $service = new PlanningConstraintService;
        $this->assertSame($expected, $service->evaluate($candidate, $free, $anchors, $sessions, $timezone, $preferences, $deadline));
        $this->assertSame($expected, $service->evaluate($candidate, $free, $anchors, $sessions, $timezone, $preferences, $deadline));
        $this->assertSame($expected, $service->evaluate(array_reverse($candidate, true), array_reverse($free), array_reverse($anchors), array_reverse($sessions), $timezone, array_reverse($preferences, true), $deadline));
        $this->assertSame($original, [$candidate, $free, $anchors, $sessions, $preferences]);
    }

    public static function candidateCases(): array
    {
        $i = self::interval(...);
        $p = self::preferences(...);
        $candidate = $i(0, 1800);
        $free = [$candidate];
        $outside = ['outside_available_study_time'];

        return [
            'fully configured valid' => [$candidate, $free, [$i(-120, -60), $i(1860, 1920)], [$i(1920, 1980)], 'UTC', $p(['min_session_minutes' => 30, 'max_session_minutes' => 30, 'preferred_break_minutes' => 1, 'max_daily_study_minutes' => 31, 'max_weekly_study_minutes' => 31]), 1800, []],
            'explicit all null' => [$candidate, $free, [], [], 'UTC', $p(), null, []],
            'one second no implicit minimum' => [$i(0, 1), [$i(0, 1)], [], [], 'UTC', $p(), null, []],
            'long candidate no implicit maximum' => [$i(0, 4000000), [$i(0, 4000000)], [], [], 'UTC', $p(), null, []],
            'below minimum' => [$candidate, $free, [], [], 'UTC', $p(['min_session_minutes' => 31]), null, ['below_minimum_session_duration']],
            'above maximum' => [$candidate, $free, [], [], 'UTC', $p(['max_session_minutes' => 29]), null, ['above_maximum_session_duration']],
            'fully inside wider free slot' => [$candidate, [$i(-1, 1801)], [], [], 'UTC', $p(), null, []],
            'starts before free slot' => [$candidate, [$i(1, 1800)], [], [], 'UTC', $p(), null, $outside],
            'ends after free slot' => [$candidate, [$i(0, 1799)], [], [], 'UTC', $p(), null, $outside],
            'spans one second free gap' => [$candidate, [$i(0, 900), $i(901, 1800)], [], [], 'UTC', $p(), null, $outside],
            'adjacent free intervals normalize' => [$candidate, [$i(900, 1800), $i(0, 900)], [], [], 'UTC', $p(), null, []],
            'overlapping free intervals normalize' => [$candidate, [$i(900, 1800), $i(0, 1000), $i(0, 1000)], [], [], 'UTC', $p(), null, []],
            'contained in one of multiple disjoint slots' => [$candidate, [$i(-100, -1), $i(0, 1800), $i(2000, 3000)], [], [], 'UTC', $p(), null, []],
            'candidate between free slots' => [$candidate, [$i(-100, 0), $i(1800, 2000)], [], [], 'UTC', $p(), null, $outside],
            'empty free intervals' => [$candidate, [], [], [], 'UTC', $p(), null, $outside],
            'overlap no configured break' => [$candidate, $free, [$i(10, 20)], [], 'UTC', $p(), null, ['overlapping_break_anchor']],
            'insufficient before' => [$candidate, $free, [$i(-100, -59)], [], 'UTC', $p(['preferred_break_minutes' => 1]), null, ['insufficient_break_before']],
            'insufficient after' => [$candidate, $free, [$i(1859, 1900)], [], 'UTC', $p(['preferred_break_minutes' => 1]), null, ['insufficient_break_after']],
            'null break permits adjacency' => [$candidate, $free, [$i(-1, 0), $i(1800, 1801)], [], 'UTC', $p(), null, []],
            'daily workload exceeded' => [$candidate, $free, [], [$i(1800, 1801)], 'UTC', $p(['max_daily_study_minutes' => 30]), null, ['daily_study_limit_exceeded']],
            'weekly workload exceeded on other day' => [$candidate, $free, [], [$i(86400, 86401)], 'UTC', $p(['max_weekly_study_minutes' => 30]), null, ['weekly_study_limit_exceeded']],
            'both workload exceeded' => [$candidate, $free, [], [$i(1800, 1801)], 'UTC', $p(['max_daily_study_minutes' => 30, 'max_weekly_study_minutes' => 30]), null, ['daily_study_limit_exceeded', 'weekly_study_limit_exceeded']],
            'deadline equality' => [$candidate, $free, [], [], 'UTC', $p(), 1800, []],
            'deadline one second exceeded' => [$candidate, $free, [], [], 'UTC', $p(), 1799, ['deadline_exceeded']],
            'deadline zero is configured' => [$candidate, $free, [], [], 'UTC', $p(), 0, ['deadline_exceeded']],
            'negative deadline equality' => [$i(-61, -1), [$i(-61, -1)], [], [], 'UTC', $p(), -1, []],
            'minimum integer deadline' => [$candidate, $free, [], [], 'UTC', $p(), PHP_INT_MIN, ['deadline_exceeded']],
            'maximum integer deadline' => [$candidate, $free, [], [], 'UTC', $p(), PHP_INT_MAX, []],
            'all simultaneous applicable codes' => [$candidate, [$i(0, 1799)], [$i(1801, 1802), $i(5, 10), $i(-100, -1), $i(5, 10)], [$i(1801, 1802)], 'UTC', $p(['min_session_minutes' => 31, 'max_session_minutes' => 40, 'preferred_break_minutes' => 1, 'max_daily_study_minutes' => 30, 'max_weekly_study_minutes' => 30]), 1799,
                ['below_minimum_session_duration', 'outside_available_study_time', 'overlapping_break_anchor', 'insufficient_break_before', 'insufficient_break_after', 'daily_study_limit_exceeded', 'weekly_study_limit_exceeded', 'deadline_exceeded']],
            'maximum violation precedes containment and deadline' => [$candidate, [], [], [], 'UTC', $p(['max_session_minutes' => 29]), 1799, ['above_maximum_session_duration', 'outside_available_study_time', 'deadline_exceeded']],
            'Kyiv local midnight split' => [$i('2026-10-12T20:30:00Z', '2026-10-12T21:30:00Z'), [$i('2026-10-12T20:30:00Z', '2026-10-12T21:30:00Z')], [], [], 'Europe/Kyiv', $p(['max_daily_study_minutes' => 30, 'max_weekly_study_minutes' => 60]), null, []],
            'Kyiv spring exact elapsed duration' => [$i('2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z'), [$i('2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z')], [], [], 'Europe/Kyiv', $p(['min_session_minutes' => 60, 'max_session_minutes' => 60, 'max_daily_study_minutes' => 60, 'max_weekly_study_minutes' => 60]), null, []],
            'Kyiv fold distinct free occurrences gap' => [$i('2026-10-25T00:15:00Z', '2026-10-25T01:45:00Z'), [$i('2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z'), $i('2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z')], [], [], 'Europe/Kyiv', $p(), null, $outside],
            'Kyiv fold workload both real occurrences' => [$i('2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z'), [$i('2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z')], [$i('2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z')], [$i('2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z')], 'Europe/Kyiv', $p(['preferred_break_minutes' => 30, 'max_daily_study_minutes' => 60, 'max_weekly_study_minutes' => 60]), null, []],
        ];
    }

    private static function preferences(array $overrides = []): array
    {
        return array_replace([
            'min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null,
            'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null,
        ], $overrides);
    }

    private static function interval(string|int $start, string|int $end): array
    {
        return ['start' => is_int($start) ? $start : (new DateTimeImmutable($start))->getTimestamp(),
            'end' => is_int($end) ? $end : (new DateTimeImmutable($end))->getTimestamp()];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_raise_exceptions_even_when_an_earlier_business_rule_fails(
        array $candidate, array $free, array $anchors, array $sessions, string $timezone, array $preferences, mixed $deadline,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        (new PlanningConstraintService)->evaluate($candidate, $free, $anchors, $sessions, $timezone, $preferences, $deadline);
    }

    public static function invalidInputs(): array
    {
        $candidate = ['start' => 0, 'end' => 60];
        // Empty free intervals ensure other input errors are not hidden by containment failure.
        $base = [$candidate, [], [], [], 'UTC', self::preferences(), null];
        $cases = [];
        foreach ([[], [0, 60], ['start' => 0, 'end' => 0], ['start' => 1, 'end' => 0], ['start' => 0], ['start' => '0', 'end' => 60], ['start' => 0, 'end' => 60.0], ['start' => 0, 'end' => 60, 'id' => 1]] as $index => $interval) {
            foreach ([0, 1, 2, 3] as $field) {
                $case = $base;
                $case[$field] = $field === 0 ? $interval : [$interval];
                $cases['interval '.$index.' field '.$field] = $case;
            }
        }
        foreach (array_keys(self::preferences()) as $key) {
            $case = $base;
            unset($case[5][$key]);
            $cases['missing '.$key] = $case;
            foreach ([0, -1, 65536, '1', 1.0, true, false, [], (object) ['minutes' => 1]] as $index => $value) {
                $case = $base;
                $case[5][$key] = $value;
                $cases[$key.' invalid '.$index] = $case;
            }
        }
        $case = $base;
        $case[5]['user_id'] = 1;
        $cases['extra preference'] = $case;
        $case = $base;
        $case[5] = [];
        $cases['no inferred missing preference defaults'] = $case;
        $case = $base;
        $case[5] = self::preferences(['min_session_minutes' => 2, 'max_session_minutes' => 1]);
        $cases['reversed durations'] = $case;
        $case = $base;
        $case[5] = self::preferences(['max_daily_study_minutes' => 2, 'max_weekly_study_minutes' => 1]);
        $cases['reversed workload'] = $case;
        foreach (['', 'Invalid/Timezone', '+03:00', 'EET'] as $timezone) {
            $case = $base;
            $case[4] = $timezone;
            $cases['timezone '.$timezone] = $case;
        }
        foreach (['60', 60.0, true, false, [], (object) ['timestamp' => 60]] as $index => $deadline) {
            $case = $base;
            $case[6] = $deadline;
            $cases['non-integer deadline '.$index] = $case;
        }
        $case = $base;
        $case[0] = ['start' => PHP_INT_MIN, 'end' => PHP_INT_MAX];
        $cases['candidate overflow'] = $case;
        $case = $base;
        $case[3] = [['start' => 0, 'end' => PHP_INT_MAX]];
        $cases['workload overflow'] = $case;
        foreach ([1, 2, 3] as $field) {
            $case = $base;
            $case[$field] = [null];
            $cases['non-array interval field '.$field] = $case;
        }

        return $cases;
    }

    public function test_existing_constraint_algorithms_are_delegated_and_business_violations_do_not_short_circuit(): void
    {
        $candidate = ['start' => 0, 'end' => 60];
        $anchors = [['start' => -1, 'end' => 0]];
        $sessions = [['start' => 60, 'end' => 61]];
        $preferences = self::preferences(['min_session_minutes' => 2, 'max_session_minutes' => 3, 'preferred_break_minutes' => 1, 'max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => 1]);
        $intervals = $this->createMock(TimeIntervalService::class);
        $intervals->expects($this->once())->method('durationSeconds')->with($candidate)->willReturn(60);
        $intervals->expects($this->once())->method('subtract')->with([$candidate], [])->willReturn([$candidate]);
        $duration = $this->createMock(SessionDurationConstraintService::class);
        $duration->expects($this->once())->method('evaluate')->with($candidate, 2, 3)->willReturn(['below_minimum_session_duration']);
        $breaks = $this->createMock(BreakConstraintService::class);
        $breaks->expects($this->once())->method('evaluate')->with($candidate, $anchors, 1)->willReturn(['insufficient_break_before']);
        $workload = $this->createMock(WorkloadConstraintService::class);
        $workload->expects($this->once())->method('evaluate')->with($candidate, $sessions, 'Europe/Kyiv', 1, 1)->willReturn(['daily_study_limit_exceeded']);
        $this->assertSame(
            ['below_minimum_session_duration', 'outside_available_study_time', 'insufficient_break_before', 'daily_study_limit_exceeded', 'deadline_exceeded'],
            (new PlanningConstraintService($intervals, $duration, $breaks, $workload))->evaluate($candidate, [], $anchors, $sessions, 'Europe/Kyiv', $preferences, 59),
        );
    }

    public function test_server_timezone_does_not_change_combined_evaluation(): void
    {
        $original = date_default_timezone_get();
        $candidate = self::interval('2026-10-12T20:30:00Z', '2026-10-12T21:30:00Z');
        try {
            foreach (['UTC', 'Europe/Kyiv', 'Pacific/Apia', 'Asia/Kathmandu'] as $serverTimezone) {
                date_default_timezone_set($serverTimezone);
                $this->assertSame([], (new PlanningConstraintService)->evaluate($candidate, [$candidate], [], [], 'Europe/Kyiv', self::preferences(['max_daily_study_minutes' => 30]), $candidate['end']));
            }
        } finally {
            date_default_timezone_set($original);
        }
    }
}
