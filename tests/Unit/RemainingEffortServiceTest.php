<?php

namespace Tests\Unit;

use App\Services\Planning\RemainingEffortService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RemainingEffortServiceTest extends TestCase
{
    #[DataProvider('accountingCases')]
    public function test_exact_actual_first_credit_and_independent_reservations(?int $estimate, array $completed, array $reserved, array $values): void
    {
        $before = [$completed, $reserved];
        $service = new RemainingEffortService;
        $expected = array_combine(['estimated_seconds', 'completed_credit_seconds', 'remaining_work_seconds',
            'future_reserved_seconds', 'unallocated_work_seconds', 'over_reserved_seconds'], $values);
        $this->assertSame($expected, $service->calculate($estimate, $completed, $reserved));
        $this->assertSame($expected, $service->calculate($estimate, array_reverse($completed), array_reverse($reserved)));
        $this->assertSame($expected, $service->calculate($estimate, $completed, $reserved));
        $this->assertSame($before, [$completed, $reserved]);
    }

    public static function accountingCases(): array
    {
        $fallback = fn (int $seconds): array => ['scheduled_seconds' => $seconds, 'actual_minutes' => null];

        return [
            'empty sources' => [60, [], [], [3600, 0, 3600, 0, 3600, 0]],
            'missing estimate is unknown' => [null, [], [], [null, 0, null, 0, null, null]],
            'missing estimate retains known credit and reservation' => [null, [$fallback(61)], [['scheduled_seconds' => 120]], [null, 61, null, 120, null, null]],
            'actual shorter than scheduled' => [60, [['scheduled_seconds' => 3600, 'actual_minutes' => 15]], [], [3600, 900, 2700, 0, 2700, 0]],
            'actual longer than scheduled' => [60, [['scheduled_seconds' => 900, 'actual_minutes' => 45]], [], [3600, 2700, 900, 0, 900, 0]],
            'null actual preserves seconds' => [60, [$fallback(901)], [], [3600, 901, 2699, 0, 2699, 0]],
            'multiple independent records' => [60, [$fallback(901), ['scheduled_seconds' => 3600, 'actual_minutes' => 15]], [], [3600, 1801, 1799, 0, 1799, 0]],
            'equal interval records remain distinct' => [60, [$fallback(900), $fallback(900)], [], [3600, 1800, 1800, 0, 1800, 0]],
            'credit equals estimate' => [60, [$fallback(3600)], [], [3600, 3600, 0, 0, 0, 0]],
            'credit exceeds estimate retained raw' => [60, [$fallback(3601)], [], [3600, 3601, 0, 0, 0, 0]],
            'reservation only does not complete work' => [60, [], [['scheduled_seconds' => 900]], [3600, 0, 3600, 900, 2700, 0]],
            'reservation exactly covers unfinished work' => [60, [$fallback(900)], [['scheduled_seconds' => 2700]], [3600, 900, 2700, 2700, 0, 0]],
            'over reservation' => [60, [$fallback(900)], [['scheduled_seconds' => 2701]], [3600, 900, 2700, 2701, 0, 1]],
            'multiple reservations keep seconds' => [60, [$fallback(900)], [['scheduled_seconds' => 901], ['scheduled_seconds' => 900]], [3600, 900, 2700, 1801, 899, 0]],
            'reservation after fully credited effort' => [60, [$fallback(3601)], [['scheduled_seconds' => 1]], [3600, 3601, 0, 1, 0, 1]],
            'maximum total without overflow' => [1, [$fallback(PHP_INT_MAX - 1), $fallback(1)], [['scheduled_seconds' => PHP_INT_MAX]], [60, PHP_INT_MAX, 0, PHP_INT_MAX, 0, PHP_INT_MAX]],
        ];
    }

    #[DataProvider('malformedArguments')]
    public function test_malformed_values_and_overflow_are_explicit(mixed $estimate, array $completed, array $reserved): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RemainingEffortService)->calculate($estimate, $completed, $reserved);
    }

    public static function malformedArguments(): array
    {
        $cases = [];
        foreach ([0, -1, '60', 60.0, true, false, [], PHP_INT_MAX, intdiv(PHP_INT_MAX, 60) + 1] as $index => $bad) {
            $cases['estimate '.$index] = [$bad, [], []];
            $cases['actual '.$index] = [60, [['scheduled_seconds' => 1, 'actual_minutes' => $bad]], []];
        }
        foreach ([0, -1, '60', 60.0, true, false, null, []] as $index => $bad) {
            $cases['completed duration '.$index] = [60, [['scheduled_seconds' => $bad, 'actual_minutes' => null]], []];
            $cases['reservation duration '.$index] = [60, [], [['scheduled_seconds' => $bad]]];
        }
        foreach ([null, 1, [], ['scheduled_seconds' => 1], ['actual_minutes' => null],
            ['scheduled_seconds' => 1, 'actual_minutes' => null, 'extra' => true]] as $index => $bad) {
            $cases['completed shape '.$index] = [60, [$bad], []];
        }
        foreach ([null, 1, [], ['start' => 1, 'end' => 2], ['scheduled_seconds' => 1, 'extra' => true]] as $index => $bad) {
            $cases['reservation shape '.$index] = [60, [], [$bad]];
        }
        $cases['completed collection must be list'] = [60, ['item' => ['scheduled_seconds' => 1, 'actual_minutes' => null]], []];
        $cases['reservation collection must be list'] = [60, [], [1 => ['scheduled_seconds' => 1]]];
        $cases['completed sum overflow'] = [60, [['scheduled_seconds' => PHP_INT_MAX, 'actual_minutes' => null], ['scheduled_seconds' => 1, 'actual_minutes' => null]], []];
        $cases['reservation sum overflow'] = [60, [], [['scheduled_seconds' => PHP_INT_MAX], ['scheduled_seconds' => 1]]];
        $cases['invalid scheduled duration even when actual exists'] = [60, [['scheduled_seconds' => 0, 'actual_minutes' => 1]], []];

        return $cases;
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
