<?php

namespace Tests\Unit;

use App\Services\Planning\AvailabilityOccurrenceService;
use App\Services\Planning\TimeIntervalService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CompactAvailabilityOccurrenceServiceTest extends TestCase
{
    #[DataProvider('occurrenceCases')]
    public function test_expansion_matches_explicit_utc_intervals_and_is_repeatable_without_input_mutation(array $windows, string $start, string $end, string $timezone, array $utcPairs, ?int $totalSeconds = null): void
    {
        $before = $windows;
        $service = new AvailabilityOccurrenceService;
        $actual = $service->expand($windows, $start, $end, $timezone);
        $expected = $this->utcIntervals($utcPairs);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected, $service->expand(array_reverse($windows), $start, $end, $timezone));
        $this->assertSame($actual, $service->expand($windows, $start, $end, $timezone));
        $this->assertSame($before, $windows);
        $this->assertNormalized($actual);
        if ($totalSeconds !== null) {
            $this->assertSame($totalSeconds, array_sum(array_map(fn (array $interval) => $interval['end'] - $interval['start'], $actual)));
        }
    }

    public static function occurrenceCases(): array
    {
        return ['empty' => [[], '2026-09-07', '2026-09-07', 'UTC', []], 'weekday does not match' => [[0 => ['day_of_week' => 2, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']], '2026-09-07', '2026-09-07', 'UTC', []], 'historical seconds offset Monrovia' => [[0 => ['day_of_week' => 4, 'starts_at' => '00:00:00', 'ends_at' => '01:00:00']], '1972-01-06', '1972-01-06', 'Africa/Monrovia', [0 => [0 => '1972-01-06T00:44:30Z', 1 => '1972-01-06T01:44:30Z']], 3600]];
    }

    #[DataProvider('invalidWindows')]
    public function test_invalid_windows_are_rejected_even_when_the_weekday_would_not_match(mixed $window): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AvailabilityOccurrenceService)->expand([$window], '2026-09-07', '2026-09-07', 'UTC');
    }

    public static function invalidWindows(): array
    {
        return [0 => [null], 1 => [1], 26 => [['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00', 'task_id' => 1]]];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_and_reversed_ranges_are_rejected_even_without_windows(string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AvailabilityOccurrenceService)->expand([], $start, $end, 'UTC');
    }

    public static function invalidDates(): array
    {
        return [0 => ['2026-09-08', '2026-09-07'], 1 => ['2026-02-29', '2026-03-01'], 12 => ['10000-01-01', '10000-01-01']];
    }

    #[DataProvider('invalidTimezones')]
    public function test_invalid_timezone_is_rejected_even_without_windows(string $timezone): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AvailabilityOccurrenceService)->expand([], '2026-09-07', '2026-09-07', $timezone);
    }

    public static function invalidTimezones(): array
    {
        return [0 => [''], 1 => ['Invalid/Zone'], 8 => ['UTC
']];
    }

    public function test_window_collection_requires_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AvailabilityOccurrenceService)->expand(['rule' => self::window()], '2026-09-07', '2026-09-07', 'UTC');
    }

    public function test_server_default_timezone_does_not_change_dates_weekdays_or_offsets(): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['Pacific/Apia', 'America/Los_Angeles', 'Asia/Kathmandu'] as $serverZone) {
                date_default_timezone_set($serverZone);
                $service = new AvailabilityOccurrenceService;
                $this->assertSame($this->utcIntervals([['2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z'], ['2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z']]), $service->expand([self::window(7, '03:15:00', '03:45:00')], '2026-10-25', '2026-10-25', 'Europe/Kyiv'));
                $this->assertSame($this->utcIntervals([['2011-12-30T09:00:00Z', '2011-12-30T12:00:00Z']]), $service->expand([self::window(5)], '2011-12-30', '2011-12-30', 'UTC'));
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_generated_occurrences_are_normalized_by_the_existing_interval_service(): void
    {
        $generated = $this->utcIntervals([['2026-09-07T09:00:00Z', '2026-09-07T12:00:00Z']]);
        $intervals = $this->createMock(TimeIntervalService::class);
        $intervals->expects($this->once())->method('merge')->with($generated)->willReturn($generated);
        $this->assertSame($generated, (new AvailabilityOccurrenceService($intervals))->expand([self::window()], '2026-09-07', '2026-09-07', 'UTC'));
    }

    private static function window(int $weekday = 1, string $start = '09:00:00', string $end = '12:00:00'): array
    {
        return ['day_of_week' => $weekday, 'starts_at' => $start, 'ends_at' => $end];
    }

    private function utcIntervals(array $pairs): array
    {
        return array_map(fn (array $pair): array => ['start' => (new DateTimeImmutable($pair[0]))->getTimestamp(), 'end' => (new DateTimeImmutable($pair[1]))->getTimestamp()], $pairs);
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
