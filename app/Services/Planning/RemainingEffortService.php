<?php

namespace App\Services\Planning;

use InvalidArgumentException;

/** Pure actual-first completed effort and separate scheduled-reservation accounting. */
class RemainingEffortService
{
    /**
     * Callers select each source record once and classify valid reservations beforehand.
     *
     * @param  ?int  $estimatedMinutes  Explicit mixed argument prevents non-strict scalar coercion.
     * @param  list<array{scheduled_seconds: int, actual_minutes: ?int}>  $completedSessions
     * @param  list<array{scheduled_seconds: int}>  $futureReservations
     * @return array{estimated_seconds: ?int, completed_credit_seconds: int, remaining_work_seconds: ?int, future_reserved_seconds: int, unallocated_work_seconds: ?int, over_reserved_seconds: ?int}
     *
     * @throws InvalidArgumentException For malformed inputs or unrepresentable integer arithmetic.
     */
    public function calculate(mixed $estimatedMinutes, array $completedSessions, array $futureReservations): array
    {
        $estimate = $estimatedMinutes === null ? null : $this->minuteSeconds($estimatedMinutes);
        if (! array_is_list($completedSessions) || ! array_is_list($futureReservations)) {
            throw new InvalidArgumentException('Expected lists of effort sources.');
        }
        $completed = $reserved = 0;
        foreach ($completedSessions as $session) {
            $this->validateSource($session, ['scheduled_seconds', 'actual_minutes']);
            $credit = $session['actual_minutes'] === null
                ? $session['scheduled_seconds'] : $this->minuteSeconds($session['actual_minutes']);
            $completed = $this->addSeconds($completed, $credit);
        }
        foreach ($futureReservations as $session) {
            $this->validateSource($session, ['scheduled_seconds']);
            $reserved = $this->addSeconds($reserved, $session['scheduled_seconds']);
        }
        $remaining = $estimate === null ? null : max(0, $estimate - $completed);

        return [
            'estimated_seconds' => $estimate,
            'completed_credit_seconds' => $completed,
            'remaining_work_seconds' => $remaining,
            'future_reserved_seconds' => $reserved,
            'unallocated_work_seconds' => $remaining === null ? null : max(0, $remaining - $reserved),
            'over_reserved_seconds' => $remaining === null ? null : max(0, $reserved - $remaining),
        ];
    }

    private function validateSource(mixed $source, array $keys): void
    {
        if (! is_array($source) || count($source) !== count($keys)
            || array_diff_key(array_flip($keys), $source) !== []
            || ! is_int($source['scheduled_seconds']) || $source['scheduled_seconds'] < 1) {
            throw new InvalidArgumentException('Expected a positive integer scheduled duration and the exact effort source fields.');
        }
    }

    private function minuteSeconds(mixed $minutes): int
    {
        if (! is_int($minutes) || $minutes < 1 || $minutes > intdiv(PHP_INT_MAX, 60)) {
            throw new InvalidArgumentException('Effort minutes must be positive integers with representable seconds.');
        }

        return $minutes * 60;
    }

    private function addSeconds(int $total, int $seconds): int
    {
        if ($total > PHP_INT_MAX - $seconds) {
            throw new InvalidArgumentException('The effort total exceeds the PHP integer range.');
        }

        return $total + $seconds;
    }
}
