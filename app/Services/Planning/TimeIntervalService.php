<?php

namespace App\Services\Planning;

use InvalidArgumentException;

/** Pure operations on half-open UTC intervals; endpoints are integer Unix seconds. */
class TimeIntervalService
{
    /**
     * @param  array<array-key, array{start: int, end: int}>  $intervals
     * @return list<array{start: int, end: int}>
     */
    public function merge(array $intervals): array
    {
        foreach ($intervals as $interval) {
            $this->validateInterval($interval);
        }
        usort($intervals, fn (array $a, array $b): int => ($a['start'] <=> $b['start']) ?: ($a['end'] <=> $b['end']));

        $result = [];
        foreach ($intervals as $interval) {
            $last = count($result) - 1;
            if ($last >= 0 && $interval['start'] <= $result[$last]['end']) {
                $result[$last]['end'] = max($result[$last]['end'], $interval['end']);
            } else {
                $result[] = ['start' => $interval['start'], 'end' => $interval['end']];
            }
        }

        return $result;
    }

    /**
     * @param  array<array-key, array{start: int, end: int}>  $baseIntervals
     * @param  array<array-key, array{start: int, end: int}>  $blockingIntervals
     * @return list<array{start: int, end: int}>
     */
    public function subtract(array $baseIntervals, array $blockingIntervals): array
    {
        $bases = $this->merge($baseIntervals);
        $blockers = $this->merge($blockingIntervals);
        $result = [];
        $index = 0;
        foreach ($bases as $base) {
            $cursor = $base['start'];
            while ($index < count($blockers)) {
                $blocker = $blockers[$index];
                if ($blocker['end'] <= $cursor) {
                    $index++;

                    continue;
                }
                if ($blocker['start'] >= $base['end']) {
                    break;
                }
                if ($blocker['start'] > $cursor) {
                    $result[] = ['start' => $cursor, 'end' => $blocker['start']];
                }
                $cursor = max($cursor, $blocker['end']);
                if ($cursor >= $base['end']) {
                    // Keep this blocker for the next base: it may span several bases.
                    break;
                }
                $index++;
            }
            if ($cursor < $base['end']) {
                $result[] = ['start' => $cursor, 'end' => $base['end']];
            }
        }

        return $result;
    }

    /**
     * @param  array<array-key, array{start: int, end: int}>  $intervals
     * @param  int  $rangeStart
     * @param  int  $rangeEnd
     * @return list<array{start: int, end: int}>
     */
    public function clip(array $intervals, mixed $rangeStart, mixed $rangeEnd): array
    {
        // Explicit checks prevent scalar coercion even when called from non-strict PHP code.
        $this->validateInterval(['start' => $rangeStart, 'end' => $rangeEnd]);
        $result = [];
        foreach ($this->merge($intervals) as $interval) {
            $start = max($rangeStart, $interval['start']);
            $end = min($rangeEnd, $interval['end']);
            if ($start < $end) {
                $result[] = ['start' => $start, 'end' => $end];
            }
        }

        return $result;
    }

    /**
     * @param  array<array-key, array{start: int, end: int}>  $intervals
     * @param  int  $minimumSeconds
     * @return list<array{start: int, end: int}>
     */
    public function filterByMinimumSeconds(array $intervals, mixed $minimumSeconds): array
    {
        if (! is_int($minimumSeconds) || $minimumSeconds <= 0) {
            throw new InvalidArgumentException('The minimum duration must be a positive integer number of seconds.');
        }

        return array_values(array_filter($this->merge($intervals), function (array $interval) use ($minimumSeconds): bool {
            // Compare without overflowing the duration of extreme integer endpoints.
            return $interval['end'] >= PHP_INT_MIN + $minimumSeconds
                && $interval['start'] <= $interval['end'] - $minimumSeconds;
        }));
    }

    /**
     * @param  array{start: int, end: int}  $interval
     *
     * @throws InvalidArgumentException If the interval is invalid or its duration cannot fit in a PHP integer.
     */
    public function durationSeconds(array $interval): int
    {
        $this->validateInterval($interval);
        if ($interval['start'] < 0 && $interval['end'] > PHP_INT_MAX + $interval['start']) {
            throw new InvalidArgumentException('The interval duration exceeds the PHP integer range.');
        }

        return $interval['end'] - $interval['start'];
    }

    private function validateInterval(mixed $interval): void
    {
        if (! is_array($interval) || count($interval) !== 2
            || ! isset($interval['start'], $interval['end'])
            || ! is_int($interval['start']) || ! is_int($interval['end'])
            || $interval['start'] >= $interval['end']) {
            throw new InvalidArgumentException('An interval must contain exactly integer start and end values with start before end.');
        }
    }
}
