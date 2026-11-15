<?php

namespace Tests\Unit;

use App\Services\Planning\BreakConstraintService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CompactBreakConstraintServiceTest extends TestCase
{
    #[DataProvider('separationCases')]
    public function test_separation_is_exact_deterministic_and_does_not_mutate_inputs(array $candidate, array $anchors, ?int $minutes, array $expected): void
    {
        $originalCandidate = $candidate;
        $originalAnchors = $anchors;
        $service = new BreakConstraintService;
        $this->assertSame($expected, $service->evaluate($candidate, $anchors, $minutes));
        $this->assertSame($expected, $service->evaluate($candidate, $anchors, $minutes));
        $this->assertSame($expected, $service->evaluate(array_reverse($candidate, true), array_reverse($anchors), $minutes));
        $this->assertSame($originalCandidate, $candidate);
        $this->assertSame($originalAnchors, $anchors);
    }

    public static function separationCases(): array
    {
        return ['empty null break' => [['start' => 0, 'end' => 3600], [], null, []], 'empty configured break' => [['start' => 0, 'end' => 3600], [], 30, []], 'wide valid candidate needs no duration subtraction' => [['start' => (-9223372036854775807 - 1), 'end' => 9223372036854775807], [0 => ['start' => 0, 'end' => 1]], null, [0 => 'overlapping_break_anchor']], 'wide merged anchors' => [['start' => 0, 'end' => 3600], [0 => ['start' => (-9223372036854775807 - 1), 'end' => 0], 1 => ['start' => 0, 'end' => 9223372036854775807]], 1, [0 => 'overlapping_break_anchor']]];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_break_configuration_is_rejected_even_without_anchors(mixed $minutes): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BreakConstraintService)->evaluate(['start' => 0, 'end' => 1], [], $minutes);
    }

    public static function invalidConfigurations(): array
    {
        return [0 => [0], 1 => [-1], 13 => [(object) ['minutes' => 1]]];
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_candidate_or_anchor_is_rejected_with_no_configured_break(array $candidate, array $anchors): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BreakConstraintService)->evaluate($candidate, $anchors);
    }

    public static function invalidIntervals(): array
    {
        return ['candidate 0' => [['start' => 0, 'end' => 0], []], 'anchor 0' => [['start' => 0, 'end' => 60], [0 => ['start' => 0, 'end' => 60], 1 => ['start' => 0, 'end' => 0]]], 'non-array anchor 5' => [['start' => 0, 'end' => 60], [0 => (object) ['start' => 0, 'end' => 60]]]];
    }

    public function test_omitted_break_is_identical_to_explicit_null(): void
    {
        $service = new BreakConstraintService;
        $candidate = ['start' => 0, 'end' => 1];
        $this->assertSame([], $service->evaluate($candidate, [['start' => 1, 'end' => 2]]));
        $this->assertSame([], $service->evaluate($candidate, [['start' => 1, 'end' => 2]], null));
    }

    public function test_timezone_and_current_clock_have_no_effect(): void
    {
        $originalTimezone = date_default_timezone_get();
        $originalClock = CarbonImmutable::getTestNow();
        try {
            foreach (['UTC', 'Europe/Kyiv', 'Pacific/Apia', 'Asia/Kathmandu'] as $timezone) {
                date_default_timezone_set($timezone);
                foreach (['1980-01-01T00:00:00Z', '2040-10-28T12:00:00Z'] as $now) {
                    CarbonImmutable::setTestNow($now);
                    $this->assertSame(['overlapping_break_anchor', 'insufficient_break_before', 'insufficient_break_after'], (new BreakConstraintService)->evaluate(['start' => 30, 'end' => 40], [['start' => 0, 'end' => 10], ['start' => 35, 'end' => 36], ['start' => 50, 'end' => 60]], 1));
                }
            }
        } finally {
            CarbonImmutable::setTestNow($originalClock);
            date_default_timezone_set($originalTimezone);
        }
    }
}
