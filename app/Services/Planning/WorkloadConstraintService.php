<?php

namespace App\Services\Planning;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;

/** Sums caller-selected scheduled workload, without merging distinct session records. */
class WorkloadConstraintService
{
    public function __construct(private TimeIntervalService $intervals = new TimeIntervalService) {}

    /**
     * @param  array{start: int, end: int}  $candidate
     * @param  list<array{start: int, end: int}>  $existingSessions  Each intended record once, excluding the candidate.
     * @param  ?int  $maxDailyMinutes
     * @param  ?int  $maxWeeklyMinutes
     * @return list<'daily_study_limit_exceeded'|'weekly_study_limit_exceeded'>
     *
     * @throws InvalidArgumentException For invalid intervals, limits, timezone or unrepresentable arithmetic.
     */
    public function evaluate(array $candidate, array $existingSessions, string $timezone, mixed $maxDailyMinutes = null, mixed $maxWeeklyMinutes = null): array
    {
        $zone = $this->validateInputs($candidate, $existingSessions, $timezone, $maxDailyMinutes, $maxWeeklyMinutes);
        if ($maxDailyMinutes === null && $maxWeeklyMinutes === null) {
            return [];
        }

        [$days, $weeks] = $this->periodTotals($candidate, $existingSessions, $zone, true);

        $violations = [];
        if ($maxDailyMinutes !== null && max($days) > $maxDailyMinutes * 60) {
            $violations[] = 'daily_study_limit_exceeded';
        }
        if ($maxWeeklyMinutes !== null && max($weeks) > $maxWeeklyMinutes * 60) {
            $violations[] = 'weekly_study_limit_exceeded';
        }

        return $violations;
    }

    /**
     * Residual budgets for a planning range, using the same C7 calendar portions and additive totals.
     * Null means unconfigured; exhausted/overloaded periods have zero additional capacity.
     *
     * @return array{segments: list<array{start: int, end: int, day: string, week: string, seconds: int}>, days: array<string, ?int>, weeks: array<string, ?int>}
     */
    public function availableCapacity(array $range, array $existingSessions, string $timezone, mixed $maxDailyMinutes = null, mixed $maxWeeklyMinutes = null): array
    {
        $zone = $this->validateInputs($range, $existingSessions, $timezone, $maxDailyMinutes, $maxWeeklyMinutes);
        [$days, $weeks] = $this->periodTotals($range,
            $maxDailyMinutes === null && $maxWeeklyMinutes === null ? [] : $existingSessions, $zone, false);

        return [
            'segments' => iterator_to_array($this->portions($range, $zone), false),
            'days' => array_map(fn (int $seconds): ?int => $maxDailyMinutes === null ? null : max(0, $maxDailyMinutes * 60 - $seconds), $days),
            'weeks' => array_map(fn (int $seconds): ?int => $maxWeeklyMinutes === null ? null : max(0, $maxWeeklyMinutes * 60 - $seconds), $weeks),
        ];
    }

    private function validateInputs(array $range, array $existingSessions, string $timezone, mixed $maxDailyMinutes, mixed $maxWeeklyMinutes): DateTimeZone
    {
        $total = $this->intervals->durationSeconds($range);
        if (! array_is_list($existingSessions)) {
            throw new InvalidArgumentException('Expected a list of existing workload sessions.');
        }
        foreach ($existingSessions as $session) {
            if (! is_array($session)) {
                throw new InvalidArgumentException('Expected a workload session interval.');
            }
            $total = $this->addSeconds($total, $this->intervals->durationSeconds($session));
        }
        foreach ([$maxDailyMinutes, $maxWeeklyMinutes] as $limit) {
            if ($limit !== null && (! is_int($limit) || $limit < 1 || $limit > 65535)) {
                throw new InvalidArgumentException('Workload limits must be null or integer minutes between 1 and 65535.');
            }
        }
        if ($maxDailyMinutes !== null && $maxWeeklyMinutes !== null && $maxWeeklyMinutes < $maxDailyMinutes) {
            throw new InvalidArgumentException('The weekly workload limit must be at least the daily limit.');
        }
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)
            && ! (str_starts_with($timezone, 'Etc/') && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))) {
            throw new InvalidArgumentException('Expected an IANA timezone name.');
        }

        return new DateTimeZone($timezone);
    }

    private function periodTotals(array $range, array $existingSessions, DateTimeZone $zone, bool $includeRange): array
    {
        $days = $weeks = [];
        foreach ($this->portions($range, $zone) as $portion) {
            $days[$portion['day']] = $this->addSeconds($days[$portion['day']] ?? 0, $includeRange ? $portion['seconds'] : 0);
            $weeks[$portion['week']] = $this->addSeconds($weeks[$portion['week']] ?? 0, $includeRange ? $portion['seconds'] : 0);
        }
        foreach ($existingSessions as $session) {
            foreach ($this->portions($session, $zone) as $portion) {
                // Only periods receiving candidate workload can acquire a new violation.
                if (isset($days[$portion['day']])) {
                    $days[$portion['day']] = $this->addSeconds($days[$portion['day']], $portion['seconds']);
                }
                if (isset($weeks[$portion['week']])) {
                    $weeks[$portion['week']] = $this->addSeconds($weeks[$portion['week']], $portion['seconds']);
                }
            }
        }

        return [$days, $weeks];
    }

    /** @return Generator<int, array{start: int, end: int, day: string, week: string, seconds: int}> */
    private function portions(array $interval, DateTimeZone $zone): Generator
    {
        $transitions = $zone->getTransitions($interval['start'], $interval['end']);
        if ($transitions === false || $transitions === []) {
            $transitions = [['ts' => $interval['start'], 'offset' => $zone->getOffset(new DateTimeImmutable('@'.$interval['start']))]];
        }
        foreach ($transitions as $index => $transition) {
            $cursor = $transition['ts'];
            $end = $transitions[$index + 1]['ts'] ?? $interval['end'];
            while ($cursor < $end) {
                // Encode local calendar labels in a UTC DateTime on this constant-offset segment.
                $wall = $this->shiftByOffset($cursor, $transition['offset']);
                $date = new DateTimeImmutable('@'.$wall);
                $midnight = $date->setTime(0, 0)->modify('+1 day')->getTimestamp();
                // A wrapped next-midnight timestamp lies beyond the representable timeline.
                $remaining = $midnight > $wall ? $midnight - $wall : PHP_INT_MAX;
                $seconds = min($end - $cursor, $remaining);
                yield ['start' => $cursor, 'end' => $cursor + $seconds, 'day' => $date->format('Y-m-d'), 'week' => $date->format('o-W'), 'seconds' => $seconds];
                $cursor += $seconds;
            }
        }
    }

    private function addSeconds(int $total, int $seconds): int
    {
        if ($total > PHP_INT_MAX - $seconds) {
            throw new InvalidArgumentException('The supplied workload total exceeds the PHP integer range.');
        }

        return $total + $seconds;
    }

    private function shiftByOffset(int $timestamp, int $offset): int
    {
        if (($offset > 0 && $timestamp > PHP_INT_MAX - $offset)
            || ($offset < 0 && $timestamp < PHP_INT_MIN - $offset)) {
            throw new InvalidArgumentException('The local calendar timestamp exceeds the PHP integer range.');
        }

        return $timestamp + $offset;
    }
}
