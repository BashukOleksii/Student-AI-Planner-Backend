<?php

namespace Tests\Unit;

use App\Services\Planning\WorkloadConstraintService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkloadCapacityTest extends TestCase
{
    #[DataProvider('ranges')]
    public function test_residual_budgets_reuse_real_c7_calendar_membership(string $zone, string $start, string $end, int $seconds, int $days): void
    {
        $range = $this->interval($start, $end);
        $service = new WorkloadConstraintService;
        $result = $service->availableCapacity($range, [], $zone, 65535, 65535);
        $this->assertSame($seconds, array_sum(array_column($result['segments'], 'seconds')));
        $this->assertCount($days, $result['days']);
        foreach ($result['segments'] as $segment) {
            $this->assertSame($segment['seconds'], $segment['end'] - $segment['start']);
        }
        $this->assertSame([], $service->evaluate($range, [], $zone, 65535, 65535));
    }

    public static function ranges(): array
    {
        return [
            ['Europe/Kyiv', '2026-03-28T22:00:00Z', '2026-03-29T21:00:00Z', 82800, 1],
            ['Europe/Kyiv', '2026-10-24T21:00:00Z', '2026-10-25T22:00:00Z', 90000, 1],
            ['UTC', '2026-10-11T23:30:00Z', '2026-10-12T00:30:00Z', 3600, 2],
            ['Pacific/Apia', '2011-12-30T09:30:00Z', '2011-12-30T10:30:00Z', 3600, 2],
            ['Asia/Kathmandu', '2026-10-12T18:00:00Z', '2026-10-12T18:30:00Z', 1800, 2],
        ];
    }

    public function test_sources_outside_range_share_week_budgets_and_equal_rows_are_not_merged(): void
    {
        $range = $this->interval('2026-10-07T10:00:00Z', '2026-10-07T12:00:00Z');
        $source = $this->interval('2026-10-05T10:00:00Z', '2026-10-05T10:15:01Z');
        $result = (new WorkloadConstraintService)->availableCapacity($range, [$source, $source], 'UTC', 60, 60);
        $this->assertSame(['2026-10-07' => 3600], $result['days']);
        $this->assertSame(['2026-41' => 1798], $result['weeks']);
    }

    public function test_overloaded_periods_have_zero_capacity_without_negative_budgets(): void
    {
        $range = $this->interval('2026-10-07T10:00:00Z', '2026-10-07T12:00:00Z');
        $source = $this->interval('2026-10-07T08:00:00Z', '2026-10-07T09:00:01Z');
        $result = (new WorkloadConstraintService)->availableCapacity($range, [$source], 'UTC', 60, 60);
        $this->assertSame(['2026-10-07' => 0], $result['days']);
        $this->assertSame(['2026-41' => 0], $result['weeks']);
    }

    public function test_null_budgets_remain_null_and_do_not_enumerate_unrelated_unlimited_history(): void
    {
        $service = new WorkloadConstraintService;
        $result = $service->availableCapacity(['start' => 0, 'end' => 1], [['start' => 0, 'end' => PHP_INT_MAX - 1]], 'UTC');
        $this->assertSame(['1970-01-01' => null], $result['days']);
        $this->assertSame(['1970-01' => null], $result['weeks']);
    }

    public function test_aggregate_overflow_is_still_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkloadConstraintService)->availableCapacity(['start' => 0, 'end' => 1], [['start' => 0, 'end' => PHP_INT_MAX]], 'UTC');
    }

    private function interval(string $start, string $end): array
    {
        return ['start' => (new DateTimeImmutable($start))->getTimestamp(), 'end' => (new DateTimeImmutable($end))->getTimestamp()];
    }
}
