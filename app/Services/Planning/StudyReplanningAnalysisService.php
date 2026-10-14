<?php

namespace App\Services\Planning;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/** Pure classification and one-to-one allocation; all persistence stays in the preview adapter. */
class StudyReplanningAnalysisService
{
    public function __construct(
        private PlanningScenarioService $scenario = new PlanningScenarioService,
        private PlanningConstraintService $constraints = new PlanningConstraintService,
        private StudySessionAllocationService $allocator = new StudySessionAllocationService,
        private TimeIntervalService $intervals = new TimeIntervalService,
    ) {}

    public function analyze(array $sources, array $snapshot, DateTimeImmutable $now): array
    {
        if (! array_is_list($sources)) {
            throw new InvalidArgumentException('Expected ordered replanning sources.');
        }
        $this->scenario->without($snapshot);
        $seen = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! is_int($source['study_session_id'] ?? null) || $source['study_session_id'] < 1
                || isset($seen[$source['study_session_id']]) || ! is_int($source['task_id'] ?? null) || $source['task_id'] < 1
                || ! array_key_exists('subtask_id', $source)
                || ($source['subtask_id'] !== null && (! is_int($source['subtask_id']) || $source['subtask_id'] < 1))
                || ! is_int($source['priority'] ?? null) || $source['priority'] < 1 || $source['priority'] > 3
                || ! array_key_exists('effective_deadline_utc', $source)
                || ($source['effective_deadline_utc'] !== null && ! is_int($source['effective_deadline_utc']))
                || ! isset($source['policy_reason_codes']) || ! is_array($source['policy_reason_codes'])
                || ! array_is_list($source['policy_reason_codes']) || ! isset($source['original']) || ! is_array($source['original'])) {
                throw new InvalidArgumentException('Malformed replanning source.');
            }
            foreach ($source['policy_reason_codes'] as $code) {
                if (! is_string($code) || $code === '') {
                    throw new InvalidArgumentException('Invalid policy reason code.');
                }
            }
            $seen[$source['study_session_id']] = true;
            $this->intervals->durationSeconds($source['original']);
        }
        $this->scenario->without($snapshot, array_column($sources, 'study_session_id'));
        $loaded = [];
        foreach ([...$snapshot['blocking_sessions'], ...$snapshot['session_anchors'], ...$snapshot['workload_records']] as $record) {
            $loaded[$record['id']] = ['start' => $record['start'], 'end' => $record['end']];
        }
        foreach ($sources as $source) {
            if ($loaded[$source['study_session_id']] !== $source['original']) {
                throw new InvalidArgumentException('Original interval differs from the trusted source.');
            }
        }
        usort($sources, fn ($a, $b) => (($a['effective_deadline_utc'] === null) <=> ($b['effective_deadline_utc'] === null))
            ?: ($a['effective_deadline_utc'] <=> $b['effective_deadline_utc']) ?: ($b['priority'] <=> $a['priority'])
            ?: ($a['original']['start'] <=> $b['original']['start']) ?: ($a['study_session_id'] <=> $b['study_session_id']));
        $results = [];
        foreach ($sources as $source) {
            $codes = $source['policy_reason_codes'];
            if ((new DateTimeImmutable('@'.$source['original']['start'])) <= $now) {
                array_unshift($codes, 'session_start_not_future');
            }
            $classification = 'cannot_replan';
            if ($codes === []) {
                $context = $this->scenario->without($snapshot, [$source['study_session_id']]);
                $codes = $this->constraints->evaluate($source['original'], $context['free_intervals'], $context['break_anchors'],
                    $context['workload_sessions'], $context['timezone'], $context['preferences'], $source['effective_deadline_utc']);
                $effort = $source['effort'] ?? null;
                if (! is_array($effort) || ! is_int($effort['remaining_work_seconds'] ?? null) || $effort['remaining_work_seconds'] < 1
                    || ! is_int($effort['future_reserved_seconds'] ?? null) || $effort['future_reserved_seconds'] < 0) {
                    throw new InvalidArgumentException('Expected authoritative positive remaining work.');
                }
                $duration = $this->intervals->durationSeconds($source['original']);
                $ownCredit = $source['effective_deadline_utc'] === null || $source['original']['end'] <= $source['effective_deadline_utc'] ? $duration : 0;
                if ($effort['future_reserved_seconds'] < $ownCredit) {
                    throw new InvalidArgumentException('Inconsistent reservation effort.');
                }
                $available = max(0, $effort['remaining_work_seconds'] - ($effort['future_reserved_seconds'] - $ownCredit));
                if ($duration > $available) {
                    $codes[] = 'future_reservation_exceeds_remaining_work';
                }
                $classification = $codes === [] ? 'valid' : 'requires_replanning';
            }
            $results[] = [...$source, 'classification' => $classification, 'reason_codes' => array_values(array_unique($codes)),
                'replacement' => null, 'replacement_reason_codes' => [], 'untransferred_seconds' => 0];
        }

        return $results;
    }

    public function propose(array $results, array $selection, array $snapshot, array $coverage, DateTimeImmutable $now): array
    {
        if (! array_is_list($results) || ! isset($selection['work_items'], $selection['excluded_items'])
            || ! is_array($selection['work_items']) || ! array_is_list($selection['work_items'])
            || ! is_array($selection['excluded_items']) || ! array_is_list($selection['excluded_items'])
            || ! array_is_list($coverage) || $this->intervals->merge($coverage) !== $coverage) {
            throw new InvalidArgumentException('Malformed projected analysis inputs.');
        }
        $sourceIds = [];
        foreach ($results as $row) {
            if (! is_array($row) || ! is_int($row['study_session_id'] ?? null) || $row['study_session_id'] < 1
                || isset($sourceIds[$row['study_session_id']]) || ! is_int($row['task_id'] ?? null) || $row['task_id'] < 1
                || ! array_key_exists('subtask_id', $row) || ($row['subtask_id'] !== null && (! is_int($row['subtask_id']) || $row['subtask_id'] < 1))
                || ! in_array($row['classification'] ?? null, ['valid', 'cannot_replan', 'requires_replanning'], true)
                || ! array_key_exists('effective_deadline_utc', $row)
                || ($row['effective_deadline_utc'] !== null && ! is_int($row['effective_deadline_utc']))
                || ! isset($row['original']) || ! is_array($row['original'])) {
                throw new InvalidArgumentException('Malformed classified source.');
            }
            $this->intervals->durationSeconds($row['original']);
            $sourceIds[$row['study_session_id']] = true;
        }
        $released = array_values(array_column(array_filter($results, fn ($row) => $row['classification'] === 'requires_replanning'), 'study_session_id'));
        $context = $this->scenario->without($snapshot, $released);
        $context['range'] = $coverage === [] ? null : ['start' => $coverage[0]['start'], 'end' => $coverage[count($coverage) - 1]['end']];
        $context['free_intervals'] = $this->intervals->subtract($context['free_intervals'],
            $context['range'] === null ? $context['free_intervals'] : $this->intervals->subtract([$context['range']], $coverage));
        if ($context['range'] !== null) {
            $context['free_intervals'] = $this->intervals->clip($context['free_intervals'], $context['range']['start'], $context['range']['end']);
        }
        $base = $context;
        $cutoff = $now->getTimestamp();
        if ($cutoff === PHP_INT_MAX) {
            throw new InvalidArgumentException('Unrepresentable future planning clock.');
        }
        $cutoff++; // Strictly future, also at exact clock equality.
        $this->allocator->allocate([], $context, $cutoff);
        $work = $totals = [];
        foreach ([...$selection['work_items'], ...$selection['excluded_items']] as $unit) {
            if (! is_array($unit) || ! is_int($unit['task_id'] ?? null) || $unit['task_id'] < 1
                || ! array_key_exists('subtask_id', $unit) || ($unit['subtask_id'] !== null && (! is_int($unit['subtask_id']) || $unit['subtask_id'] < 1))
                || ! array_key_exists('effort', $unit)) {
                throw new InvalidArgumentException('Malformed projected work.');
            }
            if ($unit['effort'] !== null) {
                if (! is_array($unit['effort'])) {
                    throw new InvalidArgumentException('Malformed projected effort.');
                }
                $key = $this->key($unit);
                foreach (['remaining_work_seconds', 'unallocated_work_seconds'] as $field) {
                    if (! array_key_exists($field, $unit['effort']) || ($unit['effort'][$field] !== null
                        && (! is_int($unit['effort'][$field]) || $unit['effort'][$field] < 0))) {
                        throw new InvalidArgumentException('Malformed projected effort.');
                    }
                }
                if (isset($work[$key])) {
                    throw new InvalidArgumentException('Duplicate projected work unit.');
                }
                $work[$key] = $unit;
                $totals[$key] = ['task_id' => $unit['task_id'], 'subtask_id' => $unit['subtask_id'],
                    'remaining_work_seconds' => $unit['effort']['remaining_work_seconds'],
                    'unallocated_before_replacements' => $unit['effort']['unallocated_work_seconds'],
                    'proposed_replacement_seconds' => 0, 'unallocated_after_replacements' => $unit['effort']['unallocated_work_seconds']];
            }
        }
        $proposals = [];
        $summary = ['inspected_sessions' => count($results), 'valid_sessions' => 0, 'cannot_replan_sessions' => 0,
            'affected_sessions' => 0, 'proposed_replacements' => 0, 'unplaced_sessions' => 0, 'partial_replacements' => 0,
            'original_affected_seconds' => 0, 'replacement_seconds' => 0, 'untransferred_seconds' => 0];
        foreach ($results as &$row) {
            if ($row['classification'] !== 'requires_replanning') {
                $summary[$row['classification'] === 'valid' ? 'valid_sessions' : 'cannot_replan_sessions']++;

                continue;
            }
            $summary['affected_sessions']++;
            $duration = $this->intervals->durationSeconds($row['original']);
            $summary['original_affected_seconds'] = $this->add($summary['original_affected_seconds'], $duration);
            $key = $this->key($row);
            $capacity = $totals[$key]['unallocated_after_replacements'] ?? 0;
            if ($capacity > 0 && isset($work[$key]) && ($work[$key]['reason_codes'] ?? []) === []) {
                $unit = $work[$key];
                $unit['effort']['unallocated_work_seconds'] = min($duration, $capacity);
                $allocation = $this->allocator->allocate([$unit], $context, $cutoff, true);
                $row['replacement'] = $allocation['sessions'][0] ?? null;
                $row['replacement_reason_codes'] = $allocation['work_results'][0]['reason_codes'];
            } else {
                $row['replacement_reason_codes'] = array_values(array_unique([...($work[$key]['reason_codes'] ?? []), 'no_replacement_capacity']));
            }
            $seconds = $row['replacement']['duration_seconds'] ?? 0;
            if ($seconds > 0) {
                $candidate = ['start' => $row['replacement']['start'], 'end' => $row['replacement']['end']];
                $context['free_intervals'] = $this->intervals->subtract($context['free_intervals'], [$candidate]);
                $context['break_anchors'][] = $candidate;
                $context['workload_sessions'][] = $candidate;
                $proposals[] = ['interval' => $candidate, 'deadline' => $row['effective_deadline_utc']];
                $totals[$key]['unallocated_after_replacements'] -= $seconds;
                $totals[$key]['proposed_replacement_seconds'] += $seconds;
                $summary['proposed_replacements']++;
                if ($seconds < $duration) {
                    $summary['partial_replacements']++;
                    $row['replacement_reason_codes'][] = 'incomplete_relocation';
                }
                $summary['replacement_seconds'] = $this->add($summary['replacement_seconds'], $seconds);
            } else {
                $summary['unplaced_sessions']++;
                $row['replacement_reason_codes'][] = 'no_replacement_capacity';
            }
            $row['replacement_reason_codes'] = array_values(array_unique($row['replacement_reason_codes']));
            $row['untransferred_seconds'] = $duration - $seconds;
        }
        unset($row);
        // Independently validate each proposal against every other proposed replacement once.
        foreach ($proposals as $index => $proposal) {
            $others = array_column(array_filter($proposals, fn ($key) => $key !== $index, ARRAY_FILTER_USE_KEY), 'interval');
            if ($this->constraints->evaluate($proposal['interval'], $base['free_intervals'], [...$base['break_anchors'], ...$others],
                [...$base['workload_sessions'], ...$others], $base['timezone'], $base['preferences'], $proposal['deadline']) !== []) {
                throw new LogicException('Invalid projected replacement batch.');
            }
        }
        $summary['untransferred_seconds'] = $summary['original_affected_seconds'] - $summary['replacement_seconds'];
        $summary['no_changes_needed'] = $summary['affected_sessions'] === 0 && $summary['cannot_replan_sessions'] === 0;

        return ['session_results' => $results, 'released_session_ids' => $released, 'work_results' => array_values($totals), 'summary' => $summary];
    }

    private function key(array $unit): string
    {
        return $unit['task_id'].':'.($unit['subtask_id'] ?? 'task');
    }

    private function add(int $left, int $right): int
    {
        if ($left > PHP_INT_MAX - $right) {
            throw new InvalidArgumentException('Replanning duration overflow.');
        }

        return $left + $right;
    }
}
