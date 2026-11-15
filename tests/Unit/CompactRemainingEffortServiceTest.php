<?php

namespace Tests\Unit;

use App\Services\Planning\RemainingEffortService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CompactRemainingEffortServiceTest extends TestCase
{
    #[DataProvider('accountingCases')]
    public function test_exact_actual_first_credit_and_independent_reservations(?int $estimate, array $completed, array $reserved, array $values): void
    {
        $before = [$completed, $reserved];
        $service = new RemainingEffortService;
        $expected = array_combine(['estimated_seconds', 'completed_credit_seconds', 'remaining_work_seconds', 'future_reserved_seconds', 'unallocated_work_seconds', 'over_reserved_seconds'], $values);
        $this->assertSame($expected, $service->calculate($estimate, $completed, $reserved));
        $this->assertSame($expected, $service->calculate($estimate, array_reverse($completed), array_reverse($reserved)));
        $this->assertSame($expected, $service->calculate($estimate, $completed, $reserved));
        $this->assertSame($before, [$completed, $reserved]);
    }

    public static function accountingCases(): array
    {
        return ['empty sources' => [60, [], [], [0 => 3600, 1 => 0, 2 => 3600, 3 => 0, 4 => 3600, 5 => 0]], 'missing estimate is unknown' => [null, [], [], [0 => null, 1 => 0, 2 => null, 3 => 0, 4 => null, 5 => null]], 'missing estimate retains known credit and reservation' => [null, [0 => ['scheduled_seconds' => 61, 'actual_minutes' => null]], [0 => ['scheduled_seconds' => 120]], [0 => null, 1 => 61, 2 => null, 3 => 120, 4 => null, 5 => null]], 'actual shorter than scheduled' => [60, [0 => ['scheduled_seconds' => 3600, 'actual_minutes' => 15]], [], [0 => 3600, 1 => 900, 2 => 2700, 3 => 0, 4 => 2700, 5 => 0]], 'multiple reservations keep seconds' => [60, [0 => ['scheduled_seconds' => 900, 'actual_minutes' => null]], [0 => ['scheduled_seconds' => 901], 1 => ['scheduled_seconds' => 900]], [0 => 3600, 1 => 900, 2 => 2700, 3 => 1801, 4 => 899, 5 => 0]], 'reservation after fully credited effort' => [60, [0 => ['scheduled_seconds' => 3601, 'actual_minutes' => null]], [0 => ['scheduled_seconds' => 1]], [0 => 3600, 1 => 3601, 2 => 0, 3 => 1, 4 => 0, 5 => 1]], 'maximum total without overflow' => [1, [0 => ['scheduled_seconds' => 9223372036854775806, 'actual_minutes' => null], 1 => ['scheduled_seconds' => 1, 'actual_minutes' => null]], [0 => ['scheduled_seconds' => 9223372036854775807]], [0 => 60, 1 => 9223372036854775807, 2 => 0, 3 => 9223372036854775807, 4 => 0, 5 => 9223372036854775807]]];
    }

    #[DataProvider('malformedArguments')]
    public function test_malformed_values_and_overflow_are_explicit(mixed $estimate, array $completed, array $reserved): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RemainingEffortService)->calculate($estimate, $completed, $reserved);
    }

    public static function malformedArguments(): array
    {
        return ['estimate 0' => [0, [], []], 'actual 0' => [60, [0 => ['scheduled_seconds' => 1, 'actual_minutes' => 0]], []], 'estimate 1' => [-1, [], []], 'actual 1' => [60, [0 => ['scheduled_seconds' => 1, 'actual_minutes' => -1]], []], 'completed sum overflow' => [60, [0 => ['scheduled_seconds' => 9223372036854775807, 'actual_minutes' => null], 1 => ['scheduled_seconds' => 1, 'actual_minutes' => null]], []], 'reservation sum overflow' => [60, [], [0 => ['scheduled_seconds' => 9223372036854775807], 1 => ['scheduled_seconds' => 1]]], 'invalid scheduled duration even when actual exists' => [60, [0 => ['scheduled_seconds' => 0, 'actual_minutes' => 1]], []]];
    }

    public function test_representable_minute_multiplication_boundary(): void
    {
        $minutes = intdiv(PHP_INT_MAX, 60);
        $result = (new RemainingEffortService)->calculate($minutes, [['scheduled_seconds' => 1, 'actual_minutes' => $minutes]], []);
        $this->assertSame($minutes * 60, $result['estimated_seconds']);
        $this->assertSame($minutes * 60, $result['completed_credit_seconds']);
        $this->assertSame(0, $result['remaining_work_seconds']);
    }

    public function test_clock_and_server_timezone_are_not_calculation_inputs(): void
    {
        $oldTimezone = date_default_timezone_get();
        $oldClock = CarbonImmutable::getTestNow();
        $service = new RemainingEffortService;
        $completed = [['scheduled_seconds' => 61, 'actual_minutes' => null]];
        $expected = $service->calculate(2, $completed, []);
        try {
            foreach (['UTC', 'Europe/Kyiv', 'Pacific/Apia'] as $timezone) {
                date_default_timezone_set($timezone);
                CarbonImmutable::setTestNow('2040-01-01T00:00:00Z');
                $this->assertSame($expected, $service->calculate(2, $completed, []));
            }
        } finally {
            date_default_timezone_set($oldTimezone);
            CarbonImmutable::setTestNow($oldClock);
        }
    }
}
