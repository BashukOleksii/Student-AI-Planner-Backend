<?php

namespace Tests\Unit;

use App\Services\Planning\AvailabilityOccurrenceService;
use App\Services\Planning\TimeIntervalService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AvailabilityOccurrenceServiceTest extends TestCase
{
    #[DataProvider('occurrenceCases')]
    public function test_expansion_matches_explicit_utc_intervals_and_is_repeatable_without_input_mutation(
        array $windows, string $start, string $end, string $timezone, array $utcPairs, ?int $totalSeconds = null,
    ): void {
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
        return [
            'empty' => [[], '2026-09-07', '2026-09-07', 'UTC', []],
            'weekday does not match' => [[self::window(2)], '2026-09-07', '2026-09-07', 'UTC', []],
            'one UTC Monday' => [[self::window()], '2026-09-07', '2026-09-07', 'UTC', [['2026-09-07T09:00:00Z', '2026-09-07T12:00:00Z']]],
            'inclusive multiple weeks' => [[self::window()], '2026-09-07', '2026-09-21', 'UTC', [
                ['2026-09-07T09:00:00Z', '2026-09-07T12:00:00Z'],
                ['2026-09-14T09:00:00Z', '2026-09-14T12:00:00Z'],
                ['2026-09-21T09:00:00Z', '2026-09-21T12:00:00Z'],
            ]],
            'ISO Sunday and Monday' => [[self::window(1), self::window(7)], '2026-09-13', '2026-09-14', 'UTC', [
                ['2026-09-13T09:00:00Z', '2026-09-13T12:00:00Z'], ['2026-09-14T09:00:00Z', '2026-09-14T12:00:00Z'],
            ]],
            'leap day and last second' => [[self::window(4, '23:59:59', '24:00:00')], '2024-02-29', '2024-02-29', 'UTC', [['2024-02-29T23:59:59Z', '2024-03-01T00:00:00Z']], 1],
            'separate same-day windows' => [[self::window(1, '14:00:00', '16:00:00'), self::window()], '2026-09-07', '2026-09-07', 'UTC', [
                ['2026-09-07T09:00:00Z', '2026-09-07T12:00:00Z'], ['2026-09-07T14:00:00Z', '2026-09-07T16:00:00Z'],
            ]],
            'adjacent windows' => [[self::window(1, '12:00:00', '13:00:00'), self::window()], '2026-09-07', '2026-09-07', 'UTC', [['2026-09-07T09:00:00Z', '2026-09-07T13:00:00Z']]],
            'overlap and duplicates normalize' => [[self::window(), self::window(1, '11:00:00', '13:00:00'), self::window()], '2026-09-07', '2026-09-07', 'UTC', [['2026-09-07T09:00:00Z', '2026-09-07T13:00:00Z']]],
            'ordinary full day' => [[self::window(1, '00:00:00', '24:00:00')], '2026-09-07', '2026-09-07', 'UTC', [['2026-09-07T00:00:00Z', '2026-09-08T00:00:00Z']], 86400],
            'Kyiv end 24 is following local midnight' => [[self::window(1, '23:00:00', '24:00:00')], '2026-09-07', '2026-09-07', 'Europe/Kyiv', [['2026-09-07T20:00:00Z', '2026-09-07T21:00:00Z']]],
            'explicit overnight windows merge across dates' => [[self::window(7, '23:00:00', '24:00:00'), self::window(1, '00:00:00', '02:00:00')], '2026-09-13', '2026-09-14', 'UTC', [['2026-09-13T23:00:00Z', '2026-09-14T02:00:00Z']]],
            'seconds are not rounded' => [[self::window(1, '09:00:01', '09:00:03')], '2026-09-07', '2026-09-07', 'UTC', [['2026-09-07T09:00:01Z', '2026-09-07T09:00:03Z']], 2],
            'year boundary' => [[self::window(4, '23:00:00', '24:00:00'), self::window(5, '00:00:00', '01:00:00')], '2026-12-31', '2027-01-01', 'UTC', [['2026-12-31T23:00:00Z', '2027-01-01T01:00:00Z']]],
            'Kyiv spring gap' => [[self::window(7, '03:15:00', '03:45:00')], '2026-03-29', '2026-03-29', 'Europe/Kyiv', []],
            'spring gap clipping start' => [[self::window(7, '03:30:00', '04:30:00')], '2026-03-29', '2026-03-29', 'Europe/Kyiv', [['2026-03-29T01:00:00Z', '2026-03-29T01:30:00Z']], 1800],
            'spring gap clipping end' => [[self::window(7, '02:30:00', '03:30:00')], '2026-03-29', '2026-03-29', 'Europe/Kyiv', [['2026-03-29T00:30:00Z', '2026-03-29T01:00:00Z']], 1800],
            'spring spanning window' => [[self::window(7, '02:30:00', '04:30:00')], '2026-03-29', '2026-03-29', 'Europe/Kyiv', [['2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z']], 3600],
            'spring 23-hour day' => [[self::window(7, '00:00:00', '24:00:00')], '2026-03-29', '2026-03-29', 'Europe/Kyiv', [['2026-03-28T22:00:00Z', '2026-03-29T21:00:00Z']], 82800],
            'Kyiv autumn fold' => [[self::window(7, '03:15:00', '03:45:00')], '2026-10-25', '2026-10-25', 'Europe/Kyiv', [
                ['2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z'], ['2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z'],
            ], 3600],
            'autumn partially repeated end' => [[self::window(7, '02:30:00', '03:30:00')], '2026-10-25', '2026-10-25', 'Europe/Kyiv', [
                ['2026-10-24T23:30:00Z', '2026-10-25T00:30:00Z'], ['2026-10-25T01:00:00Z', '2026-10-25T01:30:00Z'],
            ]],
            'autumn partially repeated start' => [[self::window(7, '03:30:00', '04:30:00')], '2026-10-25', '2026-10-25', 'Europe/Kyiv', [
                ['2026-10-25T00:30:00Z', '2026-10-25T01:00:00Z'], ['2026-10-25T01:30:00Z', '2026-10-25T02:30:00Z'],
            ]],
            'autumn spanning window' => [[self::window(7, '02:30:00', '04:30:00')], '2026-10-25', '2026-10-25', 'Europe/Kyiv', [['2026-10-24T23:30:00Z', '2026-10-25T02:30:00Z']], 10800],
            'autumn 25-hour day' => [[self::window(7, '00:00:00', '24:00:00')], '2026-10-25', '2026-10-25', 'Europe/Kyiv', [['2026-10-24T21:00:00Z', '2026-10-25T22:00:00Z']], 90000],
            'exact fold seconds' => [[self::window(7, '03:15:01', '03:15:02')], '2026-10-25', '2026-10-25', 'Europe/Kyiv', [
                ['2026-10-25T00:15:01Z', '2026-10-25T00:15:02Z'], ['2026-10-25T01:15:01Z', '2026-10-25T01:15:02Z'],
            ], 2],
            'non-hour offset Kathmandu' => [[self::window()], '2026-09-07', '2026-09-07', 'Asia/Kathmandu', [['2026-09-07T03:15:00Z', '2026-09-07T06:15:00Z']]],
            'fixed Etc east offset' => [[self::window()], '2026-09-07', '2026-09-07', 'Etc/GMT-3', [['2026-09-07T06:00:00Z', '2026-09-07T09:00:00Z']]],
            'fixed Etc west offset' => [[self::window()], '2026-09-07', '2026-09-07', 'Etc/GMT+3', [['2026-09-07T12:00:00Z', '2026-09-07T15:00:00Z']]],
            'Apia skipped date' => [[self::window(5, '00:00:00', '24:00:00')], '2011-12-30', '2011-12-30', 'Pacific/Apia', []],
            'Apia dates around skip' => [[self::window(4, '00:00:00', '24:00:00'), self::window(5, '00:00:00', '24:00:00'), self::window(6, '00:00:00', '24:00:00')], '2011-12-29', '2011-12-31', 'Pacific/Apia', [['2011-12-29T10:00:00Z', '2011-12-31T10:00:00Z']], 172800],
            'midnight gap full day Sao Paulo' => [[self::window(7, '00:00:00', '24:00:00')], '2018-11-04', '2018-11-04', 'America/Sao_Paulo', [['2018-11-04T03:00:00Z', '2018-11-05T02:00:00Z']], 82800],
            'midnight gap Sao Paulo' => [[self::window(7, '00:15:00', '00:45:00')], '2018-11-04', '2018-11-04', 'America/Sao_Paulo', []],
            'midnight partial gap Sao Paulo' => [[self::window(7, '00:30:00', '01:30:00')], '2018-11-04', '2018-11-04', 'America/Sao_Paulo', [['2018-11-04T03:00:00Z', '2018-11-04T03:30:00Z']]],
            'midnight fold Havana' => [[self::window(7, '00:15:00', '00:45:00')], '2026-11-01', '2026-11-01', 'America/Havana', [
                ['2026-11-01T04:15:00Z', '2026-11-01T04:45:00Z'], ['2026-11-01T05:15:00Z', '2026-11-01T05:45:00Z'],
            ]],
            'half-hour fold Lord Howe' => [[self::window(7, '01:45:00', '01:55:00')], '2026-04-05', '2026-04-05', 'Australia/Lord_Howe', [
                ['2026-04-04T14:45:00Z', '2026-04-04T14:55:00Z'], ['2026-04-04T15:15:00Z', '2026-04-04T15:25:00Z'],
            ], 1200],
            'half-hour gap Lord Howe' => [[self::window(7, '02:10:00', '02:20:00')], '2026-10-04', '2026-10-04', 'Australia/Lord_Howe', []],
            'historical seconds offset Monrovia' => [[self::window(4, '00:00:00', '01:00:00')], '1972-01-06', '1972-01-06', 'Africa/Monrovia', [['1972-01-06T00:44:30Z', '1972-01-06T01:44:30Z']], 3600],
        ];
    }

    #[DataProvider('invalidWindows')]
    public function test_invalid_windows_are_rejected_even_when_the_weekday_would_not_match(mixed $window): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AvailabilityOccurrenceService)->expand([$window], '2026-09-07', '2026-09-07', 'UTC');
    }

    public static function invalidWindows(): array
    {
        $valid = self::window();

        return [
            [null], [1], ['window'], [(object) $valid], [[]], [array_diff_key($valid, ['starts_at' => true])],
            [array_replace($valid, ['day_of_week' => 0])], [array_replace($valid, ['day_of_week' => 8])],
            [array_replace($valid, ['day_of_week' => '1'])], [array_replace($valid, ['day_of_week' => 1.0])],
            [array_replace($valid, ['day_of_week' => true])], [array_replace($valid, ['starts_at' => null])],
            [array_replace($valid, ['starts_at' => '9:00:00'])], [array_replace($valid, ['starts_at' => '09:00'])],
            [array_replace($valid, ['starts_at' => '09:00:00.5'])], [array_replace($valid, ['starts_at' => '09:00:60'])],
            [array_replace($valid, ['starts_at' => '24:00:00'])], [array_replace($valid, ['starts_at' => ' 09:00:00'])],
            [array_replace($valid, ['ends_at' => '25:00:00'])], [array_replace($valid, ['ends_at' => '24:00:01'])],
            [array_replace($valid, ['ends_at' => '24:01:00'])], [array_replace($valid, ['ends_at' => '12:60:00'])],
            [array_replace($valid, ['ends_at' => "12:00:00\n"])], [array_replace($valid, ['ends_at' => 120000])],
            [array_replace($valid, ['ends_at' => '09:00:00'])], [self::window(1, '23:00:00', '02:00:00')],
            [array_replace($valid, ['task_id' => 1])],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_and_reversed_ranges_are_rejected_even_without_windows(string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AvailabilityOccurrenceService)->expand([], $start, $end, 'UTC');
    }

    public static function invalidDates(): array
    {
        return [
            ['2026-09-08', '2026-09-07'], ['2026-02-29', '2026-03-01'], ['2026-02-30', '2026-03-01'],
            ['2026-09-07', '2026-09-31'], ['2026-00-01', '2026-09-07'], ['0000-01-01', '2026-09-07'],
            ['2026-9-07', '2026-09-07'], ['2026-09-07 ', '2026-09-07'], ['2026-09-07', "2026-09-07\n"],
            ['2026-09-07T00:00:00Z', '2026-09-07'], ['', '2026-09-07'], ['2026-09-07', 'not-a-date'],
            ['10000-01-01', '10000-01-01'],
        ];
    }

    #[DataProvider('invalidTimezones')]
    public function test_invalid_timezone_is_rejected_even_without_windows(string $timezone): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AvailabilityOccurrenceService)->expand([], '2026-09-07', '2026-09-07', $timezone);
    }

    public static function invalidTimezones(): array
    {
        return [[''], ['Invalid/Zone'], ['+03:00'], ['EST'], ['EEST'], [' UTC'], ['UTC '], ['utc'], ["UTC\n"]];
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
                $this->assertSame($this->utcIntervals([
                    ['2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z'], ['2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z'],
                ]), $service->expand([self::window(7, '03:15:00', '03:45:00')], '2026-10-25', '2026-10-25', 'Europe/Kyiv'));
                // Server-local parsing would incorrectly skip this otherwise valid UTC Friday.
                $this->assertSame($this->utcIntervals([['2011-12-30T09:00:00Z', '2011-12-30T12:00:00Z']]),
                    $service->expand([self::window(5)], '2011-12-30', '2011-12-30', 'UTC'));
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

    /** Convert explicit expected UTC endpoints to the C1 representation; no local timezone resolution. */
    private function utcIntervals(array $pairs): array
    {
        return array_map(fn (array $pair): array => [
            'start' => (new DateTimeImmutable($pair[0]))->getTimestamp(),
            'end' => (new DateTimeImmutable($pair[1]))->getTimestamp(),
        ], $pairs);
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
