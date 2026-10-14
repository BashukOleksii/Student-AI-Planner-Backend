<?php

namespace App\Services\Planning;

use App\Enums\LessonStatus;
use App\Enums\StudySessionStatus;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class FreeTimeService
{
    public function __construct(
        private AvailabilityOccurrenceService $availability,
        private TimeIntervalService $intervals,
    ) {}

    /**
     * Read owned allowed study time; no preferences, clock-based policy or persistence.
     * Dates are inclusive local labels; the range contains at most 31 calendar dates.
     *
     * @param  ?int  $minimumSeconds  A positive integer when supplied; checked explicitly to prevent coercion.
     * @return list<array{start: int, end: int}>
     *
     * @throws InvalidArgumentException For invalid range/minimum inputs or malformed UTC source values.
     */
    public function calculate(User $user, string $startDate, string $endDate, mixed $minimumSeconds = null): array
    {
        if ($minimumSeconds !== null) {
            $this->intervals->filterByMinimumSeconds([], $minimumSeconds);
        }
        $sources = $this->sources($user, $startDate, $endDate);
        $blockers = array_map(fn ($record) => ['start' => $record['start'], 'end' => $record['end']],
            [...$sources['blocking_lessons'], ...$sources['blocking_sessions']]);
        $free = $this->intervals->subtract($sources['allowed_intervals'], $blockers);

        return $minimumSeconds === null ? $free : $this->intervals->filterByMinimumSeconds($free, $minimumSeconds);
    }

    /** The same PF1 source policy, retaining identities for trusted read-only projections. */
    public function sources(User $user, string $startDate, string $endDate): array
    {
        $start = $this->calendarDate($startDate);
        $end = $this->calendarDate($endDate);
        if ($end < $start || $start->diff($end)->days >= 31) {
            throw new InvalidArgumentException('Expected an increasing inclusive range of at most 31 calendar dates.');
        }

        $windows = $user->studyAvailabilityWindows()->get(['day_of_week', 'starts_at', 'ends_at'])
            ->map(fn ($window): array => $window->only(['day_of_week', 'starts_at', 'ends_at']))->all();
        $allowed = $this->availability->expand($windows, $startDate, $endDate, $user->timezone);
        if ($allowed === []) {
            return ['allowed_intervals' => [], 'blocking_lessons' => [], 'blocking_sessions' => []];
        }

        // C2's normalized union provides a safe envelope without resolving ambiguous local boundaries.
        $rangeStart = gmdate('Y-m-d H:i:s', $allowed[0]['start']);
        $rangeEnd = gmdate('Y-m-d H:i:s', $allowed[count($allowed) - 1]['end']);
        $lessons = $user->lessons()->where('status', LessonStatus::Active)
            ->where('starts_at', '<', $rangeEnd)->where('ends_at', '>', $rangeStart)
            ->get(['id', 'starts_at', 'ends_at']);
        $sessions = $user->studySessions()->where('status', StudySessionStatus::Planned)
            ->where('starts_at', '<', $rangeEnd)->where('ends_at', '>', $rangeStart)
            ->get(['id', 'starts_at', 'ends_at']);
        $records = function ($events): array {
            return $events->map(function ($event): array {
                // DATETIME is UTC wall-clock storage; model presentation casts can use a different timezone.
                return ['id' => $event->id,
                    'start' => $this->utcSeconds($event->getRawOriginal('starts_at')),
                    'end' => $this->utcSeconds($event->getRawOriginal('ends_at')),
                ];
            })->all();
        };

        return ['allowed_intervals' => $allowed, 'blocking_lessons' => $records($lessons), 'blocking_sessions' => $records($sessions)];
    }

    private function calendarDate(string $value): DateTimeImmutable
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
            || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException('Expected a valid calendar date in YYYY-MM-DD format.');
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    }

    private function utcSeconds(string $value): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Expected a valid whole-second UTC database timestamp.');
        }

        return $date->getTimestamp();
    }
}
