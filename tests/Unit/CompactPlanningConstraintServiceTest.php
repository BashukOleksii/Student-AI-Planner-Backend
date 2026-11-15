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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CompactPlanningConstraintServiceTest extends TestCase
{
    #[DataProvider('candidateCases')]
    public function test_combined_evaluation_returns_all_violations_in_stable_order_without_mutation(array $candidate, array $free, array $anchors, array $sessions, string $timezone, array $preferences, ?int $deadline, array $expected): void
    {
        $original = [$candidate, $free, $anchors, $sessions, $preferences];
        $service = new PlanningConstraintService;
        $this->assertSame($expected, $service->evaluate($candidate, $free, $anchors, $sessions, $timezone, $preferences, $deadline));
        $this->assertSame($expected, $service->evaluate($candidate, $free, $anchors, $sessions, $timezone, $preferences, $deadline));
        $this->assertSame($expected, $service->evaluate(array_reverse($candidate, true), array_reverse($free), array_reverse($anchors), array_reverse($sessions), $timezone, array_reverse($preferences, true), $deadline));
        $this->assertSame($original, [$candidate, $free, $anchors, $sessions, $preferences]);
    }

    public static function candidateCases(): array
    {
        return ['fully configured valid' => [['start' => 0, 'end' => 1800], [0 => ['start' => 0, 'end' => 1800]], [0 => ['start' => -120, 'end' => -60], 1 => ['start' => 1860, 'end' => 1920]], [0 => ['start' => 1920, 'end' => 1980]], 'UTC', ['min_session_minutes' => 30, 'max_session_minutes' => 30, 'preferred_break_minutes' => 1, 'max_daily_study_minutes' => 31, 'max_weekly_study_minutes' => 31], 1800, []], 'explicit all null' => [['start' => 0, 'end' => 1800], [0 => ['start' => 0, 'end' => 1800]], [], [], 'UTC', ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], null, []], 'Kyiv fold workload both real occurrences' => [['start' => 1792890900, 'end' => 1792892700], [0 => ['start' => 1792890900, 'end' => 1792892700]], [0 => ['start' => 1792887300, 'end' => 1792889100]], [0 => ['start' => 1792887300, 'end' => 1792889100]], 'Europe/Kyiv', ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => 30, 'max_daily_study_minutes' => 60, 'max_weekly_study_minutes' => 60], null, []]];
    }

    private static function preferences(array $overrides = []): array
    {
        return array_replace(['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], $overrides);
    }

    private static function interval(string|int $start, string|int $end): array
    {
        return ['start' => is_int($start) ? $start : (new DateTimeImmutable($start))->getTimestamp(), 'end' => is_int($end) ? $end : (new DateTimeImmutable($end))->getTimestamp()];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_raise_exceptions_even_when_an_earlier_business_rule_fails(array $candidate, array $free, array $anchors, array $sessions, string $timezone, array $preferences, mixed $deadline): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PlanningConstraintService)->evaluate($candidate, $free, $anchors, $sessions, $timezone, $preferences, $deadline);
    }

    public static function invalidInputs(): array
    {
        return ['interval 0 field 0' => [[], [], [], [], 'UTC', ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], null], 'interval 0 field 1' => [['start' => 0, 'end' => 60], [0 => []], [], [], 'UTC', ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], null], 'non-array interval field 3' => [['start' => 0, 'end' => 60], [], [], [0 => null], 'UTC', ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null], null]];
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
        $this->assertSame(['below_minimum_session_duration', 'outside_available_study_time', 'insufficient_break_before', 'daily_study_limit_exceeded', 'deadline_exceeded'], (new PlanningConstraintService($intervals, $duration, $breaks, $workload))->evaluate($candidate, [], $anchors, $sessions, 'Europe/Kyiv', $preferences, 59));
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
