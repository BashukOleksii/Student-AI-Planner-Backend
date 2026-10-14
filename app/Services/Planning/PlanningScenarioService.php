<?php

namespace App\Services\Planning;

use InvalidArgumentException;

/** Pure identity-based projection of a trusted PF1/C9 snapshot. No database or clock. */
class PlanningScenarioService
{
    private const SOURCES = ['blocking_lessons', 'blocking_sessions', 'lesson_anchors', 'session_anchors', 'workload_records'];

    public function __construct(private TimeIntervalService $intervals = new TimeIntervalService) {}

    public static function validateIds(array $ids): void
    {
        if (! array_is_list($ids) || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            throw new InvalidArgumentException('Expected unique ordered reservation identifiers.');
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Reservation identifiers must be positive integers.');
            }
        }
    }

    /** Exclusions remove planned session identities, never equal intervals or Lesson identities. */
    public function without(array $snapshot, array $ids = []): array
    {
        self::validateIds($ids);
        foreach (['range', 'timezone', 'preferences', 'allowed_intervals'] as $key) {
            if (! array_key_exists($key, $snapshot)) {
                throw new InvalidArgumentException('Incomplete scenario context.');
            }
        }
        if (! is_string($snapshot['timezone']) || ! is_array($snapshot['preferences']) || ! is_array($snapshot['allowed_intervals'])
            || ! array_is_list($snapshot['allowed_intervals'])) {
            throw new InvalidArgumentException('Malformed scenario context.');
        }
        $this->intervals->merge($snapshot['allowed_intervals']);
        if ($snapshot['range'] !== null) {
            $this->intervals->durationSeconds($snapshot['range']);
            if ($this->intervals->subtract($snapshot['allowed_intervals'], [$snapshot['range']]) !== []) {
                throw new InvalidArgumentException('Availability exceeds scenario range.');
            }
        } elseif ($snapshot['allowed_intervals'] !== []) {
            throw new InvalidArgumentException('An empty range cannot contain availability.');
        }
        $planned = $knownSessions = [];
        foreach (self::SOURCES as $key) {
            if (! isset($snapshot[$key]) || ! is_array($snapshot[$key]) || ! array_is_list($snapshot[$key])) {
                throw new InvalidArgumentException('Incomplete scenario sources.');
            }
            $seen = [];
            foreach ($snapshot[$key] as $record) {
                if (! is_array($record) || ! is_int($record['id'] ?? null) || $record['id'] < 1 || isset($seen[$record['id']])) {
                    throw new InvalidArgumentException('Invalid or duplicate source identity.');
                }
                $seen[$record['id']] = true;
                $interval = $this->interval($record);
                $this->intervals->durationSeconds($interval);
                if (in_array($key, ['blocking_sessions', 'session_anchors', 'workload_records'], true)) {
                    $status = $key === 'workload_records' ? ($record['status'] ?? null) : 'planned';
                    if (! in_array($status, ['planned', 'completed'], true)) {
                        throw new InvalidArgumentException('Invalid scenario workload status.');
                    }
                    $identity = [...$interval, 'status' => $status];
                    if (isset($knownSessions[$record['id']]) && $knownSessions[$record['id']] !== $identity) {
                        throw new InvalidArgumentException('Inconsistent scenario source identity.');
                    }
                    $knownSessions[$record['id']] = $identity;
                    if ($status === 'planned') {
                        $planned[$record['id']] = true;
                    }
                }
            }
        }
        foreach ($ids as $id) {
            if (! isset($planned[$id])) {
                throw new InvalidArgumentException('Only loaded owned planned reservations can be excluded.');
            }
        }
        $excluded = array_fill_keys($ids, true);
        $keep = fn ($record) => ! isset($excluded[$record['id']]);
        $blocking = [...$snapshot['blocking_lessons'], ...array_filter($snapshot['blocking_sessions'], $keep)];
        $anchors = [...$snapshot['lesson_anchors'], ...array_filter($snapshot['session_anchors'], $keep)];
        $workload = array_filter($snapshot['workload_records'], $keep);

        return ['range' => $snapshot['range'], 'timezone' => $snapshot['timezone'], 'preferences' => $snapshot['preferences'],
            'free_intervals' => $this->intervals->subtract($snapshot['allowed_intervals'], array_map($this->interval(...), $blocking)),
            'break_anchors' => array_values(array_map($this->interval(...), $anchors)),
            'workload_sessions' => array_values(array_map($this->interval(...), $workload))];
    }

    /** Merge calendar chunks by identity: workload records are never merged by interval equality. */
    public function combine(array $snapshots): array
    {
        if ($snapshots === [] || ! array_is_list($snapshots)) {
            throw new InvalidArgumentException('Expected scenario snapshots.');
        }
        $result = $snapshots[0];
        foreach ($snapshots as $snapshot) {
            $this->without($snapshot);
            if ($snapshot['timezone'] !== $result['timezone'] || $snapshot['preferences'] !== $result['preferences']) {
                throw new InvalidArgumentException('Planning settings changed between source reads.');
            }
        }
        foreach (self::SOURCES as $key) {
            $records = [];
            foreach ($snapshots as $snapshot) {
                foreach ($snapshot[$key] as $row) {
                    if (isset($records[$row['id']]) && $records[$row['id']] !== $row) {
                        throw new InvalidArgumentException('Planning sources changed between calendar reads.');
                    }
                    $records[$row['id']] = $row;
                }
            }
            ksort($records);
            $result[$key] = array_values($records);
        }
        $result['allowed_intervals'] = $this->intervals->merge(array_merge(...array_column($snapshots, 'allowed_intervals')));
        $ranges = $this->intervals->merge(array_values(array_filter(array_column($snapshots, 'range'))));
        $result['range'] = $ranges === [] ? null : ['start' => $ranges[0]['start'], 'end' => $ranges[count($ranges) - 1]['end']];

        return [...$result, ...$this->without($result)];
    }

    private function interval(array $record): array
    {
        return ['start' => $record['start'] ?? null, 'end' => $record['end'] ?? null];
    }
}
