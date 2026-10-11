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
        $total = $this->intervals->durationSeconds($candidate);
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
        $zone = new DateTimeZone($timezone);
        if ($maxDailyMinutes === null && $maxWeeklyMinutes === null) {
            return [];
        }

        $days = $weeks = [];
        foreach ($this->portions($candidate, $zone) as $portion) {
            $days[$portion['day']] = $this->addSeconds($days[$portion['day']] ?? 0, $portion['seconds']);
            $weeks[$portion['week']] = $this->addSeconds($weeks[$portion['week']] ?? 0, $portion['seconds']);
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

        $violations = [];
        if ($maxDailyMinutes !== null && max($days) > $maxDailyMinutes * 60) {
            $violations[] = 'daily_study_limit_exceeded';
        }
        if ($maxWeeklyMinutes !== null && max($weeks) > $maxWeeklyMinutes * 60) {
            $violations[] = 'weekly_study_limit_exceeded';
        }

        return $violations;
    }

    /** @return Generator<int, array{day: string, week: string, seconds: int}> */
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
                yield ['day' => $date->format('Y-m-d'), 'week' => $date->format('o-W'), 'seconds' => $seconds];
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
