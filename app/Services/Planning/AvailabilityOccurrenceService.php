<?php

namespace App\Services\Planning;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Expands recurring wall-clock membership into real half-open UTC intervals. */
class AvailabilityOccurrenceService
{
    // Search padding covers IANA offsets and date-line changes, not a local day's duration.
    private const OFFSET_SEARCH_PADDING = 172800;

    public function __construct(private TimeIntervalService $intervals = new TimeIntervalService) {}

    /**
     * @param  list<array{day_of_week: int, starts_at: string, ends_at: string}>  $windows
     * @return list<array{start: int, end: int}>
     *
     * @throws InvalidArgumentException For malformed dates, windows or timezone names.
     */
    public function expand(array $windows, string $startDate, string $endDate, string $timezone): array
    {
        $start = $this->calendarDate($startDate);
        $end = $this->calendarDate($endDate);
        if ($start > $end) {
            throw new InvalidArgumentException('The end date must not precede the start date.');
        }
        // Match persisted region names and UTC, also allowing named fixed-offset Etc zones.
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)
            && ! (str_starts_with($timezone, 'Etc/') && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))) {
            throw new InvalidArgumentException('Expected an IANA timezone name.');
        }
        $zone = new DateTimeZone($timezone);
        $byWeekday = $this->weekdayWindows($windows);
        $segments = $this->offsetSegments($zone,
            $start->getTimestamp() - self::OFFSET_SEARCH_PADDING,
            $end->modify('+1 day')->getTimestamp() + self::OFFSET_SEARCH_PADDING,
        );

        $occurrences = [];
        // UTC dates here encode nominal calendar labels only, so even skipped local dates are visited.
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            foreach ($byWeekday[(int) $date->format('N')] ?? [] as $window) {
                $wallStart = $date->getTimestamp() + $window['start'];
                $wallEnd = $date->getTimestamp() + $window['end'];
                foreach ($segments as $segment) {
                    // On this real segment, local label = UTC instant + constant offset.
                    $utcStart = max($segment['start'], $wallStart - $segment['offset']);
                    $utcEnd = min($segment['end'], $wallEnd - $segment['offset']);
                    if ($utcStart < $utcEnd) {
                        $occurrences[] = ['start' => $utcStart, 'end' => $utcEnd];
                    }
                }
            }
        }

        return $this->intervals->merge($occurrences);
    }

    private function calendarDate(string $value): DateTimeImmutable
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
            || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException('Expected a valid calendar date in YYYY-MM-DD format.');
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    }

    /** @return array<int, list<array{start: int, end: int}>> */
    private function weekdayWindows(array $windows): array
    {
        if (! array_is_list($windows)) {
            throw new InvalidArgumentException('Expected a list of recurring availability windows.');
        }
        $result = [];
        foreach ($windows as $window) {
            if (! is_array($window) || count($window) !== 3
                || ! is_int($window['day_of_week'] ?? null) || $window['day_of_week'] < 1 || $window['day_of_week'] > 7
                || ! is_string($window['starts_at'] ?? null) || ! is_string($window['ends_at'] ?? null)
                || ! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/', $window['starts_at'])
                || ! preg_match('/\A(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]|24:00:00)\z/', $window['ends_at'])
                || $window['starts_at'] >= $window['ends_at']) {
                throw new InvalidArgumentException('Expected an ISO weekday and increasing local HH:MM:SS times; only an end may be 24:00:00.');
            }
            $result[$window['day_of_week']][] = [
                'start' => $this->wallSeconds($window['starts_at']),
                'end' => $this->wallSeconds($window['ends_at']),
            ];
        }

        return $result;
    }

    private function wallSeconds(string $time): int
    {
        return (int) substr($time, 0, 2) * 3600 + (int) substr($time, 3, 2) * 60 + (int) substr($time, 6, 2);
    }

    /** @return list<array{start: int, end: int, offset: int}> */
    private function offsetSegments(DateTimeZone $zone, int $start, int $end): array
    {
        $transitions = $zone->getTransitions($start, $end);
        if ($transitions === false || $transitions === []) {
            return [['start' => $start, 'end' => $end, 'offset' => $zone->getOffset(new DateTimeImmutable('@'.$start))]];
        }
        $segments = [];
        foreach ($transitions as $index => $transition) {
            $segments[] = [
                'start' => $transition['ts'],
                'end' => $transitions[$index + 1]['ts'] ?? $end,
                'offset' => $transition['offset'],
            ];
        }

        return $segments;
    }
}
