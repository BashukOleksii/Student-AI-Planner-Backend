<?php

namespace Tests\Unit;

use App\Services\Planning\WorkloadConstraintService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CompactWorkloadConstraintServiceTest extends TestCase
{
    #[DataProvider('workloadCases')]
    public function test_exact_scheduled_workload_is_deterministic_and_inputs_are_unchanged(array $candidate, array $sessions, string $timezone, ?int $daily, ?int $weekly, array $expected): void
    {
        $originalCandidate = $candidate;
        $originalSessions = $sessions;
        $service = new WorkloadConstraintService;
        $this->assertSame($expected, $service->evaluate($candidate, $sessions, $timezone, $daily, $weekly));
        $this->assertSame($expected, $service->evaluate($candidate, $sessions, $timezone, $daily, $weekly));
        $this->assertSame($expected, $service->evaluate(array_reverse($candidate, true), array_reverse($sessions), $timezone, $daily, $weekly));
        $this->assertSame($originalCandidate, $candidate);
        $this->assertSame($originalSessions, $sessions);
    }

    public static function workloadCases(): array
    {
        return ['no limits no sessions' => [['start' => 1791795600, 'end' => 1791799200], [], 'UTC', null, null, []], 'no limits existing workload' => [['start' => 1791795600, 'end' => 1791799200], [0 => ['start' => 1791766800, 'end' => 1791792000]], 'UTC', null, null, []], 'exact daily' => [['start' => 1791795600, 'end' => 1791799200], [], 'UTC', 60, null, []], 'representable grand total with no limits' => [['start' => 0, 'end' => 1], [0 => ['start' => 0, 'end' => 9223372036854775806]], 'UTC', null, null, []], 'integer maximum second' => [['start' => 9223372036854775806, 'end' => 9223372036854775807], [], 'UTC', 1, 1, []], 'integer minimum second' => [['start' => (-9223372036854775807 - 1), 'end' => -9223372036854775807], [], 'UTC', 1, 1, []]];
    }

    private static function interval(string|int $start, string|int $end): array
    {
        return ['start' => is_int($start) ? $start : (new DateTimeImmutable($start))->getTimestamp(), 'end' => is_int($end) ? $end : (new DateTimeImmutable($end))->getTimestamp()];
    }

    #[DataProvider('invalidInputs')]
    public function test_malformed_input_is_rejected_even_when_limits_are_absent(array $candidate, array $sessions, string $timezone, mixed $daily, mixed $weekly): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkloadConstraintService)->evaluate($candidate, $sessions, $timezone, $daily, $weekly);
    }

    public static function invalidInputs(): array
    {
        return ['candidate 0' => [[], [], 'UTC', null, null], 'session 0' => [['start' => 0, 'end' => 1], [0 => []], 'UTC', null, null], 'candidate 1' => [[0 => 0, 1 => 60], [], 'UTC', null, null], 'positive local offset overflow' => [['start' => 9223372036854775806, 'end' => 9223372036854775807], [], 'Etc/GMT-2', 1, null], 'negative local offset overflow' => [['start' => (-9223372036854775807 - 1), 'end' => -9223372036854775807], [], 'Etc/GMT+2', 1, null]];
    }

    public function test_server_timezone_does_not_change_calendar_allocation(): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'Europe/Kyiv', 'Pacific/Apia', 'Asia/Kathmandu'] as $serverTimezone) {
                date_default_timezone_set($serverTimezone);
                $this->assertSame([], (new WorkloadConstraintService)->evaluate(self::interval('2026-10-12T20:30:00Z', '2026-10-12T21:30:00Z'), [], 'Europe/Kyiv', 30, 60));
            }
        } finally {
            date_default_timezone_set($original);
        }
    }
}
