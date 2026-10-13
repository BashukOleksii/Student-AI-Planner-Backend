<?php

namespace App\Services\Planning;

use InvalidArgumentException;
use LogicException;

/** Pure earliest-feasible greedy allocation against a projected, in-memory C9 context. */
class StudySessionAllocationService
{
    public function __construct(
        private TimeIntervalService $intervals = new TimeIntervalService,
        private PlanningConstraintService $constraints = new PlanningConstraintService,
        private WorkloadConstraintService $workload = new WorkloadConstraintService,
    ) {}

    /**
     * @param  list<array>  $workItems  Stage A eligible units in their authoritative order.
     * @param  array  $context  PlanningContextService's range/timezone/preferences/free/anchor/workload arrays.
     * @param  int  $notBeforeUtc  First whole second at or after Stage A's full-precision clock.
     * @return array{sessions: list<array>, work_results: list<array>, summary: array}
     */
    public function allocate(array $workItems, array $context, mixed $notBeforeUtc): array
    {
        if (! is_int($notBeforeUtc) || ! array_is_list($workItems)) {
            throw new InvalidArgumentException('Expected ordered work items and an integer planning cutoff.');
        }
        foreach (['range', 'timezone', 'preferences', 'free_intervals', 'break_anchors', 'workload_sessions'] as $key) {
            if (! array_key_exists($key, $context)) {
                throw new InvalidArgumentException('Incomplete planning context.');
            }
        }
        foreach (['free_intervals', 'break_anchors', 'workload_sessions'] as $key) {
            if (! is_array($context[$key]) || ! array_is_list($context[$key])) {
                throw new InvalidArgumentException('Expected lists of planning intervals.');
            }
        }
        $range = $context['range'];
        if (! is_string($context['timezone']) || ! is_array($context['preferences']) || ($range !== null && ! is_array($range))) {
            throw new InvalidArgumentException('Malformed planning context fields.');
        }
        if ($range !== null) {
            $this->intervals->durationSeconds($range);
            if ($this->intervals->subtract($context['free_intervals'], [$range]) !== []) {
                throw new InvalidArgumentException('Free intervals must remain inside the planning range.');
            }
        } elseif ($context['free_intervals'] !== []) {
            throw new InvalidArgumentException('An empty calendar range cannot contain free intervals.');
        }
        // Reuse C8 to validate all configuration/source inputs even when there is no eligible work.
        $probeStart = $range['start'] ?? 0;
        $this->constraints->evaluate(['start' => $probeStart, 'end' => $probeStart + 1],
            $context['free_intervals'], $context['break_anchors'], $context['workload_sessions'],
            $context['timezone'], $context['preferences']);
        $preferences = $context['preferences'];
        $minimum = ($preferences['min_session_minutes'] ?? 0) * 60;
        // One second is interval validity, not a product session-length default.
        $minimum = max(1, $minimum);
        $maximum = $preferences['max_session_minutes'] === null ? null : $preferences['max_session_minutes'] * 60;
        $totalRequested = 0;
        $seen = [];
        foreach ($workItems as $work) {
            if (! is_array($work) || ! is_int($work['task_id'] ?? null) || $work['task_id'] < 1
                || ! array_key_exists('subtask_id', $work)
                || ($work['subtask_id'] !== null && (! is_int($work['subtask_id']) || $work['subtask_id'] < 1))
                || ! array_key_exists('effective_deadline_utc', $work)
                || ($work['effective_deadline_utc'] !== null && ! is_int($work['effective_deadline_utc']))
                || ! is_int($work['effort']['unallocated_work_seconds'] ?? null) || $work['effort']['unallocated_work_seconds'] < 1) {
                throw new InvalidArgumentException('Expected eligible Stage A work with positive integer unallocated effort.');
            }
            $key = $work['task_id'].':'.($work['subtask_id'] ?? 'task');
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Each planning work unit must occur once.');
            }
            $seen[$key] = true;
            $totalRequested = $this->add($totalRequested, $work['effort']['unallocated_work_seconds']);
        }
        $future = $range === null || $notBeforeUtc >= $range['end'] ? []
            : $this->intervals->clip($context['free_intervals'], max($range['start'], $notBeforeUtc), $range['end']);
        $state = ['free' => $future, 'anchors' => $context['break_anchors'], 'workload' => $context['workload_sessions']];
        $sessions = $results = [];
        $allocatedTotal = 0;
        foreach ($workItems as $work) {
            $requested = $remaining = $work['effort']['unallocated_work_seconds'];
            $deadline = $work['effective_deadline_utc'];
            while ($remaining >= $minimum && ($candidate = $this->placement($remaining, $deadline, $state, $context, $minimum, $maximum)) !== null) {
                $duration = $this->intervals->durationSeconds($candidate);
                $tail = $remaining - $duration;
                // Reduce only when a valid second minimum-sized session can actually be placed.
                if ($tail > 0 && $tail < $minimum && $remaining >= 2 * $minimum && $remaining - $minimum >= $minimum) {
                    $balanced = ['start' => $candidate['start'], 'end' => $candidate['start'] + $remaining - $minimum];
                    $preview = $this->project($state, $balanced);
                    if ($this->placement($minimum, $deadline, $preview, $context, $minimum, $maximum) !== null) {
                        $candidate = $balanced;
                        $duration = $this->intervals->durationSeconds($candidate);
                    }
                }
                $violations = $this->constraints->evaluate($candidate, $state['free'], $state['anchors'], $state['workload'],
                    $context['timezone'], $preferences, $deadline);
                if ($violations !== []) {
                    throw new LogicException('The allocator produced a constraint-invalid candidate.');
                }
                $sessions[] = ['task_id' => $work['task_id'], 'subtask_id' => $work['subtask_id'],
                    'start' => $candidate['start'], 'end' => $candidate['end'], 'duration_seconds' => $duration];
                $state = $this->project($state, $candidate);
                $remaining -= $duration;
                $allocatedTotal = $this->add($allocatedTotal, $duration);
            }
            $reasons = $remaining === 0 ? [] : $this->reasons($remaining, $deadline, $state, $context, $minimum, $maximum);
            $results[] = ['task_id' => $work['task_id'], 'subtask_id' => $work['subtask_id'], 'requested_seconds' => $requested,
                'allocated_seconds' => $requested - $remaining, 'unscheduled_seconds' => $remaining, 'reason_codes' => $reasons];
        }

        return ['sessions' => $sessions, 'work_results' => $results, 'summary' => ['requested_seconds' => $totalRequested,
            'allocated_seconds' => $allocatedTotal, 'unscheduled_seconds' => $totalRequested - $allocatedTotal,
            'fully_allocated' => $allocatedTotal === $totalRequested]];
    }

    private function placement(int $remaining, ?int $deadline, array $state, array $context, int $minimum, ?int $maximum): ?array
    {
        if ($context['range'] === null || $remaining < $minimum) {
            return null;
        }
        $budget = $this->workload->availableCapacity($context['range'], $state['workload'], $context['timezone'],
            $context['preferences']['max_daily_study_minutes'], $context['preferences']['max_weekly_study_minutes']);
        foreach ($this->separatedFree($state, $context['preferences']['preferred_break_minutes']) as $slot) {
            $end = $deadline === null ? $slot['end'] : min($slot['end'], $deadline);
            if ($end <= $slot['start'] || $end - $slot['start'] < $minimum) {
                continue;
            }
            $start = $this->earliestStart($slot['start'], $end - $minimum, $minimum, $budget);
            if ($start === null) {
                continue;
            }
            $limit = min($remaining, $maximum ?? $remaining, $end - $start);
            $candidateEnd = $this->longestEnd($start, $start + $limit, $budget);
            if ($candidateEnd - $start >= $minimum) {
                return ['start' => $start, 'end' => $candidateEnd];
            }
        }

        return null;
    }

    /** UTC break padding yields search boundaries; C6/C8 remain the acceptance authority. */
    private function separatedFree(array $state, ?int $breakMinutes): array
    {
        $seconds = ($breakMinutes ?? 0) * 60;
        $blocked = [];
        foreach ($this->intervals->merge($state['anchors']) as $anchor) {
            if ($anchor['start'] < PHP_INT_MIN + $seconds || $anchor['end'] > PHP_INT_MAX - $seconds) {
                throw new InvalidArgumentException('A projected break boundary exceeds the integer range.');
            }
            $blocked[] = ['start' => $anchor['start'] - $seconds, 'end' => $anchor['end'] + $seconds];
        }

        return $this->intervals->subtract($state['free'], $blocked);
    }

    /**
     * Sliding a minimum-length interval is affine between calendar boundaries and their minimum-shifted counterparts.
     * Each period's contribution changes by -1, 0 or +1 per elapsed second. Solve those integer bounds directly.
     */
    private function earliestStart(int $first, int $last, int $minimum, array $budget): ?int
    {
        $points = [$first, $last + 1];
        foreach ($budget['segments'] as $segment) {
            foreach ([$segment['start'], $segment['end']] as $point) {
                foreach ([$point, $point >= PHP_INT_MIN + $minimum ? $point - $minimum : PHP_INT_MIN] as $boundary) {
                    if ($boundary > $first && $boundary <= $last) {
                        $points[] = $boundary;
                    }
                }
            }
        }
        $points = array_values(array_unique($points));
        sort($points, SORT_REGULAR);
        for ($i = 0; $i < count($points) - 1; $i++) {
            $left = $points[$i];
            $upper = $points[$i + 1] - $left - 1;
            $lower = 0;
            $base = $this->contributions($left, $minimum, $budget['segments']);
            $next = $upper === 0 ? $base : $this->contributions($left + 1, $minimum, $budget['segments']);
            foreach (['days', 'weeks'] as $kind) {
                foreach ($budget[$kind] as $label => $capacity) {
                    if ($capacity === null) {
                        continue;
                    }
                    $used = $base[$kind][$label] ?? 0;
                    $slope = ($next[$kind][$label] ?? 0) - $used;
                    if ($slope === 1) {
                        $upper = min($upper, $capacity - $used);
                    } elseif ($slope === -1) {
                        $lower = max($lower, $used - $capacity);
                    } elseif ($slope !== 0) {
                        throw new LogicException('Invalid calendar contribution slope.');
                    } elseif ($used > $capacity) {
                        $upper = -1;
                    }
                }
            }
            if ($lower <= $upper) {
                return $left + $lower;
            }
        }

        return null;
    }

    private function contributions(int $start, int $seconds, array $segments): array
    {
        $result = ['days' => [], 'weeks' => []];
        foreach ($segments as $segment) {
            $length = min($start + $seconds, $segment['end']) - max($start, $segment['start']);
            if ($length > 0) {
                foreach (['days' => 'day', 'weeks' => 'week'] as $kind => $field) {
                    $label = $segment[$field];
                    $result[$kind][$label] = ($result[$kind][$label] ?? 0) + $length;
                }
            }
        }

        return $result;
    }

    private function longestEnd(int $start, int $end, array $budget): int
    {
        $used = ['days' => [], 'weeks' => []];
        $cursor = $start;
        foreach ($budget['segments'] as $segment) {
            if ($segment['end'] <= $cursor || $segment['start'] >= $end) {
                continue;
            }
            $length = min($end, $segment['end']) - $cursor;
            $take = $length;
            foreach (['days' => 'day', 'weeks' => 'week'] as $kind => $field) {
                $label = $segment[$field];
                $capacity = $budget[$kind][$label];
                if ($capacity !== null) {
                    $take = min($take, $capacity - ($used[$kind][$label] ?? 0));
                }
            }
            foreach (['days' => 'day', 'weeks' => 'week'] as $kind => $field) {
                $label = $segment[$field];
                $used[$kind][$label] = ($used[$kind][$label] ?? 0) + $take;
            }
            $cursor += $take;
            if ($take < $length || $cursor === $end) {
                break;
            }
        }

        return $cursor;
    }

    private function project(array $state, array $candidate): array
    {
        $state['free'] = $this->intervals->subtract($state['free'], [$candidate]);
        $state['anchors'][] = $candidate;
        $state['workload'][] = $candidate;

        return $state;
    }

    /** Reasons describe evidence encountered during this greedy search, not a global impossibility proof. */
    private function reasons(int $remaining, ?int $deadline, array $state, array $context, int $minimum, ?int $maximum): array
    {
        $observed = [];
        if ($remaining < $minimum) {
            // No slot search was necessary: neither horizon nor workload caused this stop.
            return ['below_minimum_session_duration'];
        }
        $hasCapacity = false;
        $deadlineClipped = false;
        foreach ($state['free'] as $slot) {
            $deadlineClipped = $deadlineClipped || ($deadline !== null && $slot['end'] > $deadline);
            $end = $deadline === null ? $slot['end'] : min($slot['end'], $deadline);
            if ($end <= $slot['start']) {
                continue;
            }
            $hasCapacity = true;
            $seconds = min($remaining, $maximum ?? $remaining, $minimum, $end - $slot['start']);
            $observed = array_merge($observed, $this->constraints->evaluate(
                ['start' => $slot['start'], 'end' => $slot['start'] + $seconds], $state['free'], $state['anchors'], $state['workload'],
                $context['timezone'], $context['preferences'], $deadline,
            ));
        }
        $order = ['below_minimum_session_duration', 'above_maximum_session_duration', 'outside_available_study_time',
            'overlapping_break_anchor', 'insufficient_break_before', 'insufficient_break_after',
            'daily_study_limit_exceeded', 'weekly_study_limit_exceeded', 'deadline_exceeded'];
        $reasons = array_values(array_intersect($order, array_unique($observed)));
        if (! $hasCapacity) {
            $reasons[] = 'no_available_study_time';
        }
        if ($deadlineClipped) {
            $reasons[] = 'deadline_capacity_exhausted';
        }
        // This records exhausted greedy search within the bounded horizon, not consumed
        // calendar time or a proof that rearranging the complete plan cannot help.
        $reasons[] = 'planning_horizon_exhausted';

        return $reasons;
    }

    private function add(int $total, int $seconds): int
    {
        if ($total > PHP_INT_MAX - $seconds) {
            throw new InvalidArgumentException('The planning effort total exceeds the integer range.');
        }

        return $total + $seconds;
    }
}
