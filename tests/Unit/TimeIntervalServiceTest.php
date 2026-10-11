<?php

namespace Tests\Unit;

use App\Services\Planning\TimeIntervalService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeIntervalServiceTest extends TestCase
{
    #[DataProvider('mergeCases')]
    public function test_merge_returns_the_exact_normalized_union(array $intervals, array $expected): void
    {
        $service = new TimeIntervalService;
        $this->assertSame($expected, $service->merge($intervals));
        $this->assertSame($expected, $service->merge(array_reverse($intervals)));
        $this->assertSame($expected, $service->merge($expected));
        $this->assertNormalized($expected);
    }

    public static function mergeCases(): array
    {
        return [
            'empty' => [[], []],
            'single' => [[['start' => 1791795600, 'end' => 1791802800]], [['start' => 1791795600, 'end' => 1791802800]]],
            'unsorted disjoint' => [[['start' => 120, 'end' => 180], ['start' => 0, 'end' => 60]], [['start' => 0, 'end' => 60], ['start' => 120, 'end' => 180]]],
            'duplicates' => [[['start' => 0, 'end' => 60], ['start' => 0, 'end' => 60]], [['start' => 0, 'end' => 60]]],
            'nested and same start' => [[['start' => 10, 'end' => 20], ['start' => 0, 'end' => 60], ['start' => 0, 'end' => 30]], [['start' => 0, 'end' => 60]]],
            'partial overlap' => [[['start' => 0, 'end' => 60], ['start' => 40, 'end' => 100]], [['start' => 0, 'end' => 100]]],
            'adjacent' => [[['start' => 0, 'end' => 60], ['start' => 60, 'end' => 120]], [['start' => 0, 'end' => 120]]],
            'one second gap' => [[['start' => 0, 'end' => 60], ['start' => 61, 'end' => 120]], [['start' => 0, 'end' => 60], ['start' => 61, 'end' => 120]]],
            'chained union' => [[['start' => 90, 'end' => 120], ['start' => 0, 'end' => 30], ['start' => 25, 'end' => 90]], [['start' => 0, 'end' => 120]]],
            'negative seconds' => [[['start' => -120, 'end' => -60], ['start' => -60, 'end' => 1]], [['start' => -120, 'end' => 1]]],
            'collection keys do not affect output' => [[8 => ['end' => 100, 'start' => 60], 'first' => ['start' => 0, 'end' => 60]], [['start' => 0, 'end' => 100]]],
            'integer extremes compare without subtraction' => [[['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX], ['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1]], [['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1], ['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX]]],
        ];
    }

    #[DataProvider('subtractCases')]
    public function test_subtraction_preserves_half_open_boundaries_and_never_intersects_blockers(array $bases, array $blockers, array $expected): void
    {
        $service = new TimeIntervalService;
        $actual = $service->subtract($bases, $blockers);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected, $service->subtract(array_reverse($bases), array_reverse($blockers)));
        $this->assertNormalized($actual);
        foreach ($actual as $slot) {
            foreach ($blockers as $blocker) {
                $this->assertFalse($slot['start'] < $blocker['end'] && $slot['end'] > $blocker['start']);
            }
        }
    }

    public static function subtractCases(): array
    {
        return [
            'empty both' => [[], [], []],
            'empty bases' => [[], [['start' => 0, 'end' => 60]], []],
            'empty blockers still normalize bases' => [[['start' => 60, 'end' => 120], ['start' => 0, 'end' => 60]], [], [['start' => 0, 'end' => 120]]],
            'identical coverage' => [[['start' => 0, 'end' => 180]], [['start' => 0, 'end' => 180]], []],
            'larger coverage' => [[['start' => 0, 'end' => 180]], [['start' => -1, 'end' => 181]], []],
            'inside' => [[['start' => 0, 'end' => 180]], [['start' => 60, 'end' => 120]], [['start' => 0, 'end' => 60], ['start' => 120, 'end' => 180]]],
            'left overlap' => [[['start' => 0, 'end' => 180]], [['start' => -60, 'end' => 60]], [['start' => 60, 'end' => 180]]],
            'right overlap' => [[['start' => 0, 'end' => 180]], [['start' => 120, 'end' => 240]], [['start' => 0, 'end' => 120]]],
            'overlapping blockers' => [[['start' => 0, 'end' => 180]], [['start' => 40, 'end' => 100], ['start' => 60, 'end' => 120]], [['start' => 0, 'end' => 40], ['start' => 120, 'end' => 180]]],
            'adjacent blockers' => [[['start' => 0, 'end' => 180]], [['start' => 60, 'end' => 90], ['start' => 90, 'end' => 120]], [['start' => 0, 'end' => 60], ['start' => 120, 'end' => 180]]],
            'outside and exact adjacency' => [[['start' => 0, 'end' => 180]], [['start' => -120, 'end' => -60], ['start' => -60, 'end' => 0], ['start' => 180, 'end' => 240], ['start' => 300, 'end' => 360]], [['start' => 0, 'end' => 180]]],
            'blocker spanning separated bases' => [[['start' => 0, 'end' => 60], ['start' => 120, 'end' => 180], ['start' => 240, 'end' => 300]], [['start' => 30, 'end' => 270]], [['start' => 0, 'end' => 30], ['start' => 270, 'end' => 300]]],
            'blocker ends in base gap' => [[['start' => 0, 'end' => 60], ['start' => 120, 'end' => 180]], [['start' => 30, 'end' => 90]], [['start' => 0, 'end' => 30], ['start' => 120, 'end' => 180]]],
            'multiple blockers spanning merged bases' => [[['start' => 60, 'end' => 180], ['start' => 0, 'end' => 90]], [['start' => 120, 'end' => 150], ['start' => 30, 'end' => 60]], [['start' => 0, 'end' => 30], ['start' => 60, 'end' => 120], ['start' => 150, 'end' => 180]]],
            'one second hole' => [[['start' => 0, 'end' => 120]], [['start' => 0, 'end' => 60], ['start' => 61, 'end' => 120]], [['start' => 60, 'end' => 61]]],
            'negative endpoints' => [[['start' => -120, 'end' => 120]], [['start' => -60, 'end' => 0]], [['start' => -120, 'end' => -60], ['start' => 0, 'end' => 120]]],
        ];
    }

    #[DataProvider('clipCases')]
    public function test_clipping_is_an_exact_sorted_intersection(array $intervals, int $start, int $end, array $expected): void
    {
        $service = new TimeIntervalService;
        $actual = $service->clip($intervals, $start, $end);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected, $service->clip(array_reverse($intervals), $start, $end));
        $this->assertNormalized($actual);
        foreach ($actual as $slot) {
            $this->assertGreaterThanOrEqual($start, $slot['start']);
            $this->assertLessThanOrEqual($end, $slot['end']);
        }
    }

    public static function clipCases(): array
    {
        return [
            'empty' => [[], 30, 70, []],
            'both sides' => [[['start' => 0, 'end' => 100]], 30, 70, [['start' => 30, 'end' => 70]]],
            'outside and touching boundaries' => [[['start' => -100, 'end' => -1], ['start' => 0, 'end' => 30], ['start' => 70, 'end' => 100]], 30, 70, []],
            'normalize while clipping' => [[['start' => 60, 'end' => 100], ['start' => 0, 'end' => 60]], 30, 70, [['start' => 30, 'end' => 70]]],
            'separated intervals' => [[['start' => 80, 'end' => 120], ['start' => 0, 'end' => 60]], 30, 100, [['start' => 30, 'end' => 60], ['start' => 80, 'end' => 100]]],
            'one second intersection' => [[['start' => -1, 'end' => 1]], 0, 10, [['start' => 0, 'end' => 1]]],
            'already inside' => [[['start' => 31, 'end' => 69]], 30, 70, [['start' => 31, 'end' => 69]]],
        ];
    }

    public function test_minimum_uses_exact_seconds_and_includes_equality_after_normalization(): void
    {
        $service = new TimeIntervalService;
        $intervals = [['start' => 300, 'end' => 359], ['start' => 0, 'end' => 60], ['start' => 120, 'end' => 181]];
        $this->assertSame([['start' => 0, 'end' => 60], ['start' => 120, 'end' => 181]], $service->filterByMinimumSeconds($intervals, 60));
        $this->assertSame([['start' => 120, 'end' => 181]], $service->filterByMinimumSeconds($intervals, 61));
        $this->assertSame([], $service->filterByMinimumSeconds($intervals, 62));
        $this->assertSame([], $service->filterByMinimumSeconds([], 1));
        $this->assertSame([['start' => 0, 'end' => 60]], $service->filterByMinimumSeconds([['start' => 30, 'end' => 60], ['start' => 0, 'end' => 30]], 60));
        $this->assertSame([['start' => -60, 'end' => 0]], $service->filterByMinimumSeconds([['start' => -60, 'end' => 0]], 60));
        $this->assertSame([['start' => 1, 'end' => 2]], $service->filterByMinimumSeconds([['start' => 1, 'end' => 2]], 1));
    }

    public function test_integer_limits_never_require_floating_point_duration_comparisons(): void
    {
        $service = new TimeIntervalService;
        $wide = [['start' => PHP_INT_MIN, 'end' => PHP_INT_MAX]];
        $this->assertSame($wide, $service->filterByMinimumSeconds($wide, PHP_INT_MAX));
        $this->assertSame([], $service->filterByMinimumSeconds([['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1]], 2));
        $this->assertSame([['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1]], $service->filterByMinimumSeconds([['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1]], 1));
        $this->assertSame(PHP_INT_MAX, $service->durationSeconds(['start' => PHP_INT_MIN, 'end' => -1]));
        $this->expectException(InvalidArgumentException::class);
        $service->durationSeconds($wide[0]);
    }

    #[DataProvider('invalidMinimums')]
    public function test_invalid_minimum_is_rejected_even_for_empty_input(mixed $minimum): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TimeIntervalService)->filterByMinimumSeconds([], $minimum);
    }

    public static function invalidMinimums(): array
    {
        return [[0], [-1], [PHP_INT_MIN], ['60'], [60.0], [true], [false], [null], [[]]];
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_clip_range_is_rejected_even_for_empty_input(mixed $start, mixed $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TimeIntervalService)->clip([], $start, $end);
    }

    public static function invalidRanges(): array
    {
        return [[0, 0], [1, 0], ['0', 60], [0, '60'], [0.0, 60], [0, 60.0], [false, 60], [0, true], [null, 60], [0, []]];
    }

    #[DataProvider('invalidIntervals')]
    public function test_malformed_intervals_are_rejected_by_every_collection_operation(mixed $interval): void
    {
        $service = new TimeIntervalService;
        $bad = [['start' => 0, 'end' => 60], $interval];
        $calls = [
            fn () => $service->merge($bad),
            fn () => $service->subtract($bad, []),
            fn () => $service->subtract([], $bad),
            fn () => $service->clip($bad, 0, 60),
            fn () => $service->filterByMinimumSeconds($bad, 1),
        ];
        if (is_array($interval)) {
            $calls[] = fn () => $service->durationSeconds($interval);
        }
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Malformed computation input must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public static function invalidIntervals(): array
    {
        return [
            'equal' => [['start' => 1, 'end' => 1]],
            'reversed' => [['start' => 2, 'end' => 1]],
            'missing start' => [['end' => 60]],
            'missing end' => [['start' => 0]],
            'empty' => [[]],
            'string start' => [['start' => '0', 'end' => 60]],
            'string end' => [['start' => 0, 'end' => '60']],
            'float start' => [['start' => 0.0, 'end' => 60]],
            'float end' => [['start' => 0, 'end' => 60.5]],
            'null' => [['start' => null, 'end' => 60]],
            'boolean' => [['start' => false, 'end' => 60]],
            'numeric tuple' => [[0, 60]],
            'extra field' => [['start' => 0, 'end' => 60, 'type' => 'lesson']],
            'nested endpoint' => [['start' => [], 'end' => 60]],
            'scalar element' => [60],
            'string element' => ['bad'],
            'object element' => [(object) ['start' => 0, 'end' => 60]],
        ];
    }

    public function test_duration_preserves_seconds_and_all_operations_leave_inputs_unchanged(): void
    {
        $service = new TimeIntervalService;
        $bases = [9 => ['end' => 181, 'start' => 120], 4 => ['start' => -1, 'end' => 60], 7 => ['start' => 60, 'end' => 120]];
        $blockers = [5 => ['start' => 60, 'end' => 120], 2 => ['start' => 59, 'end' => 61]];
        $before = $bases;
        $blockingBefore = $blockers;
        $service->merge($bases);
        $this->assertSame(61, $service->durationSeconds($bases[9]));
        $this->assertSame([['start' => -1, 'end' => 59], ['start' => 120, 'end' => 181]], $service->subtract($bases, $blockers));
        $service->clip($bases, 0, 180);
        $service->filterByMinimumSeconds($bases, 60);
        $this->assertSame($before, $bases);
        $this->assertSame($blockingBefore, $blockers);
    }

    public function test_composed_operations_are_order_independent_and_timezone_independent(): void
    {
        $bases = [['start' => -120, 'end' => 60], ['start' => 90, 'end' => 181]];
        $blockers = [['start' => -60, 'end' => 0], ['start' => 120, 'end' => 150]];
        $expected = [['start' => 0, 'end' => 60], ['start' => 90, 'end' => 120], ['start' => 150, 'end' => 180]];
        $service = new TimeIntervalService;
        $originalZone = date_default_timezone_get();
        try {
            foreach (['UTC', 'Europe/Kyiv', 'Pacific/Apia', 'America/Los_Angeles'] as $zone) {
                date_default_timezone_set($zone);
                foreach ([$bases, array_reverse($bases)] as $baseOrder) {
                    foreach ([$blockers, array_reverse($blockers)] as $blockOrder) {
                        $actual = $service->filterByMinimumSeconds($service->clip($service->subtract($baseOrder, $blockOrder), -30, 180), 30);
                        $this->assertSame($expected, $actual);
                        $this->assertNormalized($actual);
                    }
                }
            }
        } finally {
            date_default_timezone_set($originalZone);
        }
    }

    private function assertNormalized(array $intervals): void
    {
        $this->assertTrue(array_is_list($intervals));
        $previousEnd = null;
        foreach ($intervals as $interval) {
            $this->assertSame(['start', 'end'], array_keys($interval));
            $this->assertIsInt($interval['start']);
            $this->assertIsInt($interval['end']);
            $this->assertLessThan($interval['end'], $interval['start']);
            if ($previousEnd !== null) {
                $this->assertGreaterThan($previousEnd, $interval['start']);
            }
            $previousEnd = $interval['end'];
        }
    }
}
