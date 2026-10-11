<?php

namespace App\Services\Planning;

use InvalidArgumentException;

/** Pure candidate validation against explicit caller-selected planning inputs. */
class PlanningConstraintService
{
    private const PREFERENCE_KEYS = [
        'min_session_minutes', 'max_session_minutes', 'preferred_break_minutes',
        'max_daily_study_minutes', 'max_weekly_study_minutes',
    ];

    public function __construct(
        private TimeIntervalService $intervals = new TimeIntervalService,
        private SessionDurationConstraintService $durations = new SessionDurationConstraintService,
        private BreakConstraintService $breaks = new BreakConstraintService,
        private WorkloadConstraintService $workload = new WorkloadConstraintService,
    ) {}

    /**
     * @param  array{start: int, end: int}  $candidate
     * @param  list<array{start: int, end: int}>  $freeIntervals
     * @param  list<array{start: int, end: int}>  $breakAnchors
     * @param  list<array{start: int, end: int}>  $existingWorkloadSessions
     * @param  array{min_session_minutes: ?int, max_session_minutes: ?int, preferred_break_minutes: ?int, max_daily_study_minutes: ?int, max_weekly_study_minutes: ?int}  $preferences
     * @param  ?int  $deadlineUtc  Checked without scalar coercion.
     * @return list<string> Unique violations in duration, containment, break, workload, deadline order.
     *
     * @throws InvalidArgumentException For malformed inputs or unrepresentable arithmetic.
     */
    public function evaluate(
        array $candidate,
        array $freeIntervals,
        array $breakAnchors,
        array $existingWorkloadSessions,
        string $timezone,
        array $preferences,
        mixed $deadlineUtc = null,
    ): array {
        if (count($preferences) !== count(self::PREFERENCE_KEYS)
            || array_diff_key(array_flip(self::PREFERENCE_KEYS), $preferences) !== []) {
            throw new InvalidArgumentException('Expected exactly the five configured planning preference fields.');
        }
        if ($deadlineUtc !== null && ! is_int($deadlineUtc)) {
            throw new InvalidArgumentException('The deadline must be null or an integer UTC Unix timestamp.');
        }
        $this->intervals->durationSeconds($candidate);

        $violations = $this->durations->evaluate($candidate, $preferences['min_session_minutes'], $preferences['max_session_minutes']);
        if ($this->intervals->subtract([$candidate], $freeIntervals) !== []) {
            $violations[] = 'outside_available_study_time';
        }
        $violations = array_merge($violations,
            $this->breaks->evaluate($candidate, $breakAnchors, $preferences['preferred_break_minutes']),
            $this->workload->evaluate($candidate, $existingWorkloadSessions, $timezone,
                $preferences['max_daily_study_minutes'], $preferences['max_weekly_study_minutes']),
        );
        if ($deadlineUtc !== null && $candidate['end'] > $deadlineUtc) {
            $violations[] = 'deadline_exceeded';
        }

        return array_values(array_unique($violations));
    }
}
