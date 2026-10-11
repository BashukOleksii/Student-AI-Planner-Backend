<?php

namespace App\Services\Planning;

use InvalidArgumentException;

/** Pure separation checks; the caller selects which intervals are break anchors. */
class BreakConstraintService
{
    public function __construct(private TimeIntervalService $intervals = new TimeIntervalService) {}

    /**
     * @param  array{start: int, end: int}  $candidate  Half-open UTC Unix seconds.
     * @param  list<array{start: int, end: int}>  $anchors
     * @param  ?int  $breakMinutes  Null or integer minutes in 1–65535; checked without coercion.
     * @return list<'overlapping_break_anchor'|'insufficient_break_before'|'insufficient_break_after'>
     *
     * @throws InvalidArgumentException For malformed intervals or invalid configured breaks.
     */
    public function evaluate(array $candidate, array $anchors, mixed $breakMinutes = null): array
    {
        $candidate = $this->intervals->merge([$candidate])[0];
        $anchors = $this->intervals->merge($anchors);
        if ($breakMinutes !== null && (! is_int($breakMinutes) || $breakMinutes < 1 || $breakMinutes > 65535)) {
            throw new InvalidArgumentException('The configured break must be null or integer minutes between 1 and 65535.');
        }

        $requiredSeconds = $breakMinutes === null ? null : $breakMinutes * 60;
        $overlap = $before = $after = false;
        foreach ($anchors as $anchor) {
            if ($anchor['end'] <= $candidate['start']) {
                if ($requiredSeconds !== null && $this->gapIsTooShort($anchor['end'], $candidate['start'], $requiredSeconds)) {
                    $before = true;
                }
            } elseif ($anchor['start'] >= $candidate['end']) {
                if ($requiredSeconds !== null && $this->gapIsTooShort($candidate['end'], $anchor['start'], $requiredSeconds)) {
                    $after = true;
                }
            } else {
                $overlap = true;
            }
        }

        $violations = [];
        if ($overlap) {
            $violations[] = 'overlapping_break_anchor';
        }
        if ($before) {
            $violations[] = 'insufficient_break_before';
        }
        if ($after) {
            $violations[] = 'insufficient_break_after';
        }

        return $violations;
    }

    private function gapIsTooShort(int $earlier, int $later, int $requiredSeconds): bool
    {
        // Compare later - earlier < requiredSeconds without overflowing that gap
        // or underflowing later - requiredSeconds at extreme integer endpoints.
        return $later < PHP_INT_MIN + $requiredSeconds || $earlier > $later - $requiredSeconds;
    }
}
