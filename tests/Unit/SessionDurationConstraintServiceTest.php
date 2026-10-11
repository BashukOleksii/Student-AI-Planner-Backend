<?php

namespace Tests\Unit;

use App\Services\Planning\SessionDurationConstraintService;
use App\Services\Planning\TimeIntervalService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SessionDurationConstraintServiceTest extends TestCase
{
    #[DataProvider('durationCases')]
    public function test_configured_bounds_use_exact_seconds_and_return_explicit_violations(
        array $interval, ?int $minimum, ?int $maximum, array $expected,
    ): void {
        $before = $interval;
        $service = new SessionDurationConstraintService;
        $this->assertSame($expected, $service->evaluate($interval, $minimum, $maximum));
        $this->assertSame($expected, $service->evaluate($interval, $minimum, $maximum));
        $this->assertSame($expected, $service->evaluate(array_reverse($interval, true), $minimum, $maximum));
        $this->assertSame($before, $interval);
    }

    public static function durationCases(): array
    {
        return [
            'both absent one second' => [['start' => 0, 'end' => 1], null, null, []],
            'both absent ordinary candidate' => [['start' => 1791795600, 'end' => 1791799200], null, null, []],
            'both absent no implicit upper limit' => [['start' => 0, 'end' => PHP_INT_MAX], null, null, []],
            'minimum equal' => [['start' => 0, 'end' => 1800], 30, null, []],
            'minimum one second below' => [['start' => 0, 'end' => 1799], 30, null, ['below_minimum_session_duration']],
            'minimum exceeded no implicit maximum' => [['start' => 0, 'end' => 3932101], 1, null, []],
            'maximum equal' => [['start' => 0, 'end' => 1800], null, 30, []],
            'maximum one second above' => [['start' => 0, 'end' => 1801], null, 30, ['above_maximum_session_duration']],
            'maximum alone allows one second' => [['start' => 0, 'end' => 1], null, 1, []],
            'between configured bounds' => [['start' => 0, 'end' => 2701], 30, 60, []],
            'both configured minimum equal' => [['start' => 0, 'end' => 1800], 30, 60, []],
            'both configured maximum equal' => [['start' => 0, 'end' => 3600], 30, 60, []],
            'both configured below' => [['start' => 0, 'end' => 1799], 30, 60, ['below_minimum_session_duration']],
            'both configured above' => [['start' => 0, 'end' => 3601], 30, 60, ['above_maximum_session_duration']],
            'equal configuration equal duration' => [['start' => 0, 'end' => 1800], 30, 30, []],
            'equal configuration shorter' => [['start' => 0, 'end' => 1799], 30, 30, ['below_minimum_session_duration']],
            'equal configuration longer' => [['start' => 0, 'end' => 1801], 30, 30, ['above_maximum_session_duration']],
            'smallest configured values' => [['start' => 10, 'end' => 70], 1, 1, []],
            'largest configured values' => [['start' => 0, 'end' => 3932100], 65535, 65535, []],
            'largest minimum one second below' => [['start' => 0, 'end' => 3932099], 65535, null, ['below_minimum_session_duration']],
            'largest maximum one second above' => [['start' => 0, 'end' => 3932101], null, 65535, ['above_maximum_session_duration']],
            'negative Unix endpoints' => [['start' => -61, 'end' => -1], 1, 1, []],
            'crosses Unix epoch' => [['start' => -30, 'end' => 30], 1, 1, []],
        ];
    }

    #[DataProvider('invalidBounds')]
    public function test_each_configured_bound_rejects_invalid_values_without_coercion(mixed $minimum, mixed $maximum): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SessionDurationConstraintService)->evaluate(['start' => 0, 'end' => 60], $minimum, $maximum);
    }

    public static function invalidBounds(): array
    {
        $values = [0, -1, 65536, PHP_INT_MIN, PHP_INT_MAX, '1', '65535', '', 1.0, 30.5, true, false, [], (object) ['minutes' => 1]];
        $cases = [];
        foreach ($values as $index => $value) {
            $cases['minimum '.$index] = [$value, null];
            $cases['maximum '.$index] = [null, $value];
        }

        return $cases;
    }

    #[DataProvider('reversedConfigurations')]
    public function test_minimum_greater_than_maximum_is_invalid_configuration_not_a_business_violation(int $minimum, int $maximum): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SessionDurationConstraintService)->evaluate(['start' => 0, 'end' => 60], $minimum, $maximum);
    }

    public static function reversedConfigurations(): array
    {
        return [[2, 1], [60, 30], [65535, 65534]];
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_interval_is_rejected_even_with_both_bounds_absent(array $interval): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SessionDurationConstraintService)->evaluate($interval);
    }

    public static function invalidIntervals(): array
    {
        return [
            [['start' => 0, 'end' => 0]], [['start' => 1, 'end' => 0]], [['end' => 60]], [['start' => 0]],
            [['start' => '0', 'end' => 60]], [['start' => 0, 'end' => '60']],
            [['start' => 0.0, 'end' => 60]], [['start' => 0, 'end' => 60.0]],
            [['start' => null, 'end' => 60]], [['start' => false, 'end' => 60]],
            [[]], [[0, 60]], [['start' => 0, 'end' => 60, 'task_id' => 1]],
        ];
    }

    public function test_duration_overflow_is_propagated_from_c1_without_float_comparisons(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The interval duration exceeds the PHP integer range.');
        (new SessionDurationConstraintService)->evaluate(['start' => PHP_INT_MIN, 'end' => PHP_INT_MAX]);
    }

    public function test_omitted_bounds_are_identical_to_explicit_null_bounds(): void
    {
        $service = new SessionDurationConstraintService;
        $interval = ['start' => 10, 'end' => 11];
        $this->assertSame([], $service->evaluate($interval));
        $this->assertSame([], $service->evaluate($interval, null, null));
    }

    public function test_exact_duration_validation_is_delegated_to_the_existing_c1_service(): void
    {
        $interval = ['start' => 0, 'end' => 61];
        $intervals = $this->createMock(TimeIntervalService::class);
        $intervals->expects($this->once())->method('durationSeconds')->with($interval)->willReturn(61);
        $this->assertSame(['above_maximum_session_duration'], (new SessionDurationConstraintService($intervals))->evaluate($interval, null, 1));
    }

    public function test_server_timezone_and_frozen_clock_do_not_affect_result(): void
    {
        $originalTimezone = date_default_timezone_get();
        $originalClock = CarbonImmutable::getTestNow();
        $service = new SessionDurationConstraintService;
        try {
            foreach (['UTC', 'Europe/Kyiv', 'Pacific/Apia', 'Asia/Kathmandu'] as $timezone) {
                date_default_timezone_set($timezone);
                foreach (['1980-01-01T00:00:00Z', '2040-10-28T12:00:00Z'] as $now) {
                    CarbonImmutable::setTestNow($now);
                    $this->assertSame(['above_maximum_session_duration'], $service->evaluate(['start' => -61, 'end' => 0], 1, 1));
                }
            }
        } finally {
            CarbonImmutable::setTestNow($originalClock);
            date_default_timezone_set($originalTimezone);
        }
    }
}
