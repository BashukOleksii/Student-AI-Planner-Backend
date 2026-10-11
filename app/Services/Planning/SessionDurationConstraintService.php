<?php

namespace App\Services\Planning;

use InvalidArgumentException;

/** Pure duration-only checks for one candidate study session; no defaults or mutations. */
class SessionDurationConstraintService
{
    public function __construct(private TimeIntervalService $intervals = new TimeIntervalService) {}

    /**
     * @param  array{start: int, end: int}  $interval  Half-open UTC Unix seconds.
     * @param  ?int  $minimumMinutes  Null or integer minutes in 1–65535; runtime checked without coercion.
     * @param  ?int  $maximumMinutes  Null or integer minutes in 1–65535; runtime checked without coercion.
     * @return list<'below_minimum_session_duration'|'above_maximum_session_duration'>
     *
     * @throws InvalidArgumentException For invalid intervals, duration overflow or invalid configured bounds.
     */
    public function evaluate(array $interval, mixed $minimumMinutes = null, mixed $maximumMinutes = null): array
    {
        $duration = $this->intervals->durationSeconds($interval);
        foreach ([$minimumMinutes, $maximumMinutes] as $bound) {
            if ($bound !== null && (! is_int($bound) || $bound < 1 || $bound > 65535)) {
                throw new InvalidArgumentException('Configured session duration bounds must be null or integer minutes between 1 and 65535.');
            }
        }
        if ($minimumMinutes !== null && $maximumMinutes !== null && $maximumMinutes < $minimumMinutes) {
            throw new InvalidArgumentException('The maximum session duration must be at least the minimum.');
        }

        $violations = [];
        if ($minimumMinutes !== null && $duration < $minimumMinutes * 60) {
            $violations[] = 'below_minimum_session_duration';
        }
        if ($maximumMinutes !== null && $duration > $maximumMinutes * 60) {
            $violations[] = 'above_maximum_session_duration';
        }

        return $violations;
    }
}
