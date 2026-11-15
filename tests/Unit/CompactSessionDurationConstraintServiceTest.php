<?php

namespace Tests\Unit;

use App\Services\Planning\SessionDurationConstraintService;
use App\Services\Planning\TimeIntervalService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CompactSessionDurationConstraintServiceTest extends TestCase
{
    #[DataProvider('durationCases')]
    public function test_configured_bounds_use_exact_seconds_and_return_explicit_violations(array $interval, ?int $minimum, ?int $maximum, array $expected): void
    {
        $before = $interval;
        $service = new SessionDurationConstraintService;
        $this->assertSame($expected, $service->evaluate($interval, $minimum, $maximum));
        $this->assertSame($expected, $service->evaluate($interval, $minimum, $maximum));
        $this->assertSame($expected, $service->evaluate(array_reverse($interval, true), $minimum, $maximum));
        $this->assertSame($before, $interval);
    }

    public static function durationCases(): array
    {
        return ['both absent one second' => [['start' => 0, 'end' => 1], null, null, []], 'crosses Unix epoch' => [['start' => -30, 'end' => 30], 1, 1, []]];
    }

    #[DataProvider('invalidBounds')]
    public function test_each_configured_bound_rejects_invalid_values_without_coercion(mixed $minimum, mixed $maximum): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SessionDurationConstraintService)->evaluate(['start' => 0, 'end' => 60], $minimum, $maximum);
    }

    public static function invalidBounds(): array
    {
        return ['minimum 0' => [0, null], 'maximum 13' => [null, (object) ['minutes' => 1]]];
    }

    #[DataProvider('reversedConfigurations')]
    public function test_minimum_greater_than_maximum_is_invalid_configuration_not_a_business_violation(int $minimum, int $maximum): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SessionDurationConstraintService)->evaluate(['start' => 0, 'end' => 60], $minimum, $maximum);
    }

    public static function reversedConfigurations(): array
    {
        return [0 => [2, 1], 2 => [65535, 65534]];
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_interval_is_rejected_even_with_both_bounds_absent(array $interval): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SessionDurationConstraintService)->evaluate($interval);
    }

    public static function invalidIntervals(): array
    {
        return [0 => [['start' => 0, 'end' => 0]], 12 => [['start' => 0, 'end' => 60, 'task_id' => 1]]];
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
