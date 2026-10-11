<?php

namespace Tests\Unit;

use App\Services\Planning\BreakConstraintService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BreakConstraintServiceTest extends TestCase
{
    #[DataProvider('separationCases')]
    public function test_separation_is_exact_deterministic_and_does_not_mutate_inputs(
        array $candidate, array $anchors, ?int $minutes, array $expected,
    ): void {
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
        $candidate = ['start' => 0, 'end' => 3600];
        $before = ['insufficient_break_before'];
        $after = ['insufficient_break_after'];
        $overlap = ['overlapping_break_anchor'];

        return [
            'empty null break' => [$candidate, [], null, []],
            'empty configured break' => [$candidate, [], 30, []],
            'null separated anchors' => [$candidate, [['start' => -2, 'end' => -1], ['start' => 3601, 'end' => 3602]], null, []],
            'null overlapping anchor' => [$candidate, [['start' => -1, 'end' => 1]], null, $overlap],
            'exact before' => [$candidate, [['start' => -100, 'end' => -60]], 1, []],
            'one second below before' => [$candidate, [['start' => -100, 'end' => -59]], 1, $before],
            'one second beyond before' => [$candidate, [['start' => -100, 'end' => -61]], 1, []],
            'exact after' => [$candidate, [['start' => 3660, 'end' => 3700]], 1, []],
            'one second below after' => [$candidate, [['start' => 3659, 'end' => 3700]], 1, $after],
            'one second beyond after' => [$candidate, [['start' => 3661, 'end' => 3700]], 1, []],
            'both sides satisfy' => [$candidate, [['start' => -100, 'end' => -60], ['start' => 3660, 'end' => 3700]], 1, []],
            'both sides fail' => [$candidate, [['start' => -100, 'end' => -59], ['start' => 3659, 'end' => 3700]], 1, [...$before, ...$after]],
            'overlap left' => [$candidate, [['start' => -10, 'end' => 1]], 1, $overlap],
            'overlap right' => [$candidate, [['start' => 3599, 'end' => 3610]], 1, $overlap],
            'anchor contains candidate' => [$candidate, [['start' => -10, 'end' => 3610]], 1, $overlap],
            'candidate contains anchor' => [$candidate, [['start' => 100, 'end' => 200]], 1, $overlap],
            'identical anchor' => [$candidate, [$candidate], null, $overlap],
            'adjacent before' => [$candidate, [['start' => -60, 'end' => 0]], 1, $before],
            'adjacent after' => [$candidate, [['start' => 3600, 'end' => 3660]], 1, $after],
            'null adjacent anchors' => [$candidate, [['start' => -60, 'end' => 0], ['start' => 3600, 'end' => 3660]], null, []],
            'far away' => [$candidate, [['start' => -10000000, 'end' => -9999999], ['start' => 10000000, 'end' => 10000001]], 65535, []],
            'one second gaps' => [$candidate, [['start' => -10, 'end' => -1], ['start' => 3601, 'end' => 3610]], 1, [...$before, ...$after]],
            'stable three code order' => [$candidate, [['start' => 3601, 'end' => 3610], ['start' => 5, 'end' => 10], ['start' => -10, 'end' => -1]], 1, [...$overlap, ...$before, ...$after]],
            'duplicate anchors one code' => [$candidate, [['start' => -10, 'end' => -1], ['start' => -10, 'end' => -1], ['start' => -30, 'end' => -20]], 1, $before],
            'overlapping before anchors' => [$candidate, [['start' => -100, 'end' => -40], ['start' => -60, 'end' => -30]], 1, $before],
            'adjacent before anchors' => [$candidate, [['start' => -100, 'end' => -60], ['start' => -60, 'end' => -1]], 1, $before],
            'merged anchor crosses candidate' => [$candidate, [['start' => -60, 'end' => 0], ['start' => 0, 'end' => 1]], 1, $overlap],
            'multiple overlaps one code' => [$candidate, [['start' => 10, 'end' => 20], ['start' => 30, 'end' => 40]], 1, $overlap],
            'thirty minute conversion' => [$candidate, [['start' => -1801, 'end' => -1800], ['start' => 5399, 'end' => 5400]], 30, $after],
            'maximum configured exact' => [['start' => 0, 'end' => 1], [['start' => -3932101, 'end' => -3932100], ['start' => 3932101, 'end' => 3932102]], 65535, []],
            'maximum configured below' => [['start' => 0, 'end' => 1], [['start' => -3932100, 'end' => -3932099], ['start' => 3932100, 'end' => 3932101]], 65535, [...$before, ...$after]],
            'UTC midnight crossing' => [['start' => 86370, 'end' => 86430], [['start' => 86300, 'end' => 86310], ['start' => 86490, 'end' => 86500]], 1, []],
            'negative endpoints' => [['start' => -3600, 'end' => -60], [['start' => -3700, 'end' => -3660], ['start' => -1, 'end' => 10]], 1, $after],
            'crosses Unix epoch' => [['start' => -30, 'end' => 30], [['start' => -100, 'end' => -90], ['start' => 90, 'end' => 100]], 1, []],
            'huge gaps on both sides' => [['start' => -1, 'end' => 1], [['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1], ['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX]], 65535, []],
            'gap exceeding maximum integer before' => [['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX], [['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1]], 1, []],
            'gap exceeding maximum integer after' => [['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1], [['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX]], 1, []],
            'near minimum short before gap' => [['start' => PHP_INT_MIN + 2, 'end' => PHP_INT_MIN + 3], [['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1]], 1, $before],
            'near minimum exact before gap' => [['start' => PHP_INT_MIN + 61, 'end' => PHP_INT_MIN + 62], [['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1]], 1, []],
            'near minimum short after gap' => [['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1], [['start' => PHP_INT_MIN + 2, 'end' => PHP_INT_MIN + 3]], 1, $after],
            'near minimum exact after gap' => [['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1], [['start' => PHP_INT_MIN + 61, 'end' => PHP_INT_MIN + 62]], 1, []],
            'near maximum short before gap' => [['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX], [['start' => PHP_INT_MAX - 3, 'end' => PHP_INT_MAX - 2]], 1, $before],
            'near maximum exact before gap' => [['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX], [['start' => PHP_INT_MAX - 62, 'end' => PHP_INT_MAX - 61]], 1, []],
            'near maximum short after gap' => [['start' => PHP_INT_MAX - 3, 'end' => PHP_INT_MAX - 2], [['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX]], 1, $after],
            'near maximum exact after gap' => [['start' => PHP_INT_MAX - 62, 'end' => PHP_INT_MAX - 61], [['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX]], 1, []],
            'wide valid candidate needs no duration subtraction' => [['start' => PHP_INT_MIN, 'end' => PHP_INT_MAX], [['start' => 0, 'end' => 1]], null, $overlap],
            'wide merged anchors' => [$candidate, [['start' => PHP_INT_MIN, 'end' => 0], ['start' => 0, 'end' => PHP_INT_MAX]], 1, $overlap],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_break_configuration_is_rejected_even_without_anchors(mixed $minutes): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BreakConstraintService)->evaluate(['start' => 0, 'end' => 1], [], $minutes);
    }

    public static function invalidConfigurations(): array
    {
        return array_map(fn ($value) => [$value], [0, -1, 65536, PHP_INT_MIN, PHP_INT_MAX, '1', '65535', '', 1.0, 30.5, true, false, [], (object) ['minutes' => 1]]);
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_candidate_or_anchor_is_rejected_with_no_configured_break(array $candidate, array $anchors): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BreakConstraintService)->evaluate($candidate, $anchors);
    }

    public static function invalidIntervals(): array
    {
        $valid = ['start' => 0, 'end' => 60];
        $invalid = [
            ['start' => 0, 'end' => 0], ['start' => 1, 'end' => 0], ['end' => 60], ['start' => 0],
            ['start' => '0', 'end' => 60], ['start' => 0, 'end' => '60'],
            ['start' => 0.0, 'end' => 60], ['start' => 0, 'end' => 60.0],
            ['start' => null, 'end' => 60], ['start' => false, 'end' => 60],
            [], [0, 60], ['start' => 0, 'end' => 60, 'event_type' => 'lesson'],
        ];
        $cases = [];
        foreach ($invalid as $index => $interval) {
            $cases['candidate '.$index] = [$interval, []];
            // A malformed later anchor must not be ignored after finding overlap.
            $cases['anchor '.$index] = [$valid, [$valid, $interval]];
        }
        foreach ([null, 'interval', 1, 1.0, false, (object) $valid] as $index => $interval) {
            $cases['non-array anchor '.$index] = [$valid, [$interval]];
        }

        return $cases;
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
                    $this->assertSame(
                        ['overlapping_break_anchor', 'insufficient_break_before', 'insufficient_break_after'],
                        (new BreakConstraintService)->evaluate(
                            ['start' => 30, 'end' => 40],
                            [['start' => 0, 'end' => 10], ['start' => 35, 'end' => 36], ['start' => 50, 'end' => 60]],
                            1,
                        ),
                    );
                }
            }
        } finally {
            CarbonImmutable::setTestNow($originalClock);
            date_default_timezone_set($originalTimezone);
        }
    }
}
