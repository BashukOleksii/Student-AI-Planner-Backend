<?php

namespace App\Services\Planning;

use App\Enums\LessonStatus;
use App\Enums\StudySessionStatus;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use UnexpectedValueException;

/** Shared read-only C9 source assembly for C10 and projected study planning. */
class PlanningContextService
{
    private const PREFERENCE_FIELDS = [
        'min_session_minutes', 'max_session_minutes', 'preferred_break_minutes',
        'max_daily_study_minutes', 'max_weekly_study_minutes',
    ];

    public function __construct(
        private TimeIntervalService $intervals,
        private AvailabilityOccurrenceService $availability,
        private FreeTimeService $freeTime,
    ) {}

    /** Dates are inclusive local labels; an optional anchor envelope preserves C10's exact selection. */
    public function load(User $user, string $startDate, string $endDate, ?array $anchorEnvelope = null): array
    {
        $this->validateDates($startDate, $endDate);
        $range = $this->dateRange($startDate, $endDate, $user->timezone);
        if ($anchorEnvelope !== null) {
            $this->intervals->durationSeconds($anchorEnvelope);
        }
        $envelope = $anchorEnvelope ?? $range;
        $row = $user->planningPreference()->first(self::PREFERENCE_FIELDS);
        $preferences = $row?->only(self::PREFERENCE_FIELDS) ?? array_fill_keys(self::PREFERENCE_FIELDS, null);
        [$weekStart, $weekEnd] = $this->workloadBounds($startDate, $endDate, $user->timezone);
        $break = $preferences['preferred_break_minutes'];
        if ($break !== null && (! is_int($break) || $break < 1 || $break > 65535)) {
            throw new InvalidArgumentException('The configured break must be null or integer minutes between 1 and 65535.');
        }
        $buffer = ($break ?? 0) * 60;
        if ($envelope !== null && ($envelope['start'] < PHP_INT_MIN + $buffer || $envelope['end'] > PHP_INT_MAX - $buffer)) {
            throw new InvalidArgumentException('The break query envelope exceeds the integer range.');
        }
        $anchorStart = $envelope === null ? null : $this->queryTimestamp($envelope['start'] - $buffer);
        $anchorEnd = $envelope === null ? null : $this->queryTimestamp($envelope['end'] + $buffer);
        if ($range !== null) {
            $this->queryTimestamp($range['start']);
            $this->queryTimestamp($range['end']);
        }
        $workload = $user->studySessions()->whereIn('status', [StudySessionStatus::Planned, StudySessionStatus::Completed])
            ->where('starts_at', '<', $weekEnd)->where('ends_at', '>', $weekStart)
            ->get(['id', 'task_id', 'subtask_id', 'starts_at', 'ends_at']);
        $sessions = $envelope === null ? new Collection : $user->studySessions()->where('status', StudySessionStatus::Planned)
            ->where('starts_at', '<', $anchorEnd)->where('ends_at', '>', $anchorStart)
            ->get(['id', 'task_id', 'subtask_id', 'starts_at', 'ends_at']);
        $this->validateSessionReferences($user, $workload->concat($sessions)->unique('id'));
        $lessons = $envelope === null ? new Collection : $user->lessons()->where('status', LessonStatus::Active)
            ->where('starts_at', '<', $anchorEnd)->where('ends_at', '>', $anchorStart)
            ->get(['starts_at', 'ends_at']);
        $free = $this->freeTime->calculate($user, $startDate, $endDate, null);

        return ['range' => $range, 'timezone' => $user->timezone, 'preferences' => $preferences,
            'free_intervals' => $free, 'break_anchors' => $this->eventIntervals($lessons->concat($sessions)),
            'workload_sessions' => $this->eventIntervals($workload)];
    }

    public function validateDates(string $startDate, string $endDate): void
    {
        $start = $this->calendarDate($startDate);
        $end = $this->calendarDate($endDate);
        if ($end < $start || $start->diff($end)->days >= 31) {
            throw new InvalidArgumentException('Expected an increasing inclusive range of at most 31 calendar dates.');
        }
    }

    /** UTC envelope of inclusive local-date membership; null for a wholly skipped date. */
    public function dateRange(string $startDate, string $endDate, string $timezone): ?array
    {
        $coverage = $this->dateIntervals($startDate, $endDate, $timezone);

        return $coverage === [] ? null : ['start' => $coverage[0]['start'], 'end' => $coverage[count($coverage) - 1]['end']];
    }

    /** Exact membership, including disjoint occurrences across historical date-line reversals. */
    public function dateIntervals(string $startDate, string $endDate, string $timezone): array
    {
        $this->validateDates($startDate, $endDate);

        return $this->fullDays($startDate, $endDate, $timezone);
    }

    private function fullDays(string $startDate, string $endDate, string $timezone): array
    {
        $windows = array_map(fn (int $day): array => [
            'day_of_week' => $day, 'starts_at' => '00:00:00', 'ends_at' => '24:00:00',
        ], range(1, 7));

        return $this->availability->expand($windows, $startDate, $endDate, $timezone);
    }

    /** Full local ISO-week membership, without resolving ambiguous/skipped local midnights. */
    private function workloadBounds(string $startDate, string $endDate, string $timezone): array
    {
        $first = $this->calendarDate($startDate);
        $last = $this->calendarDate($endDate);
        $monday = $first->modify('-'.((int) $first->format('N') - 1).' days');
        $sunday = $last->modify('+'.(7 - (int) $last->format('N')).' days');
        $coverage = $this->fullDays($monday->format('Y-m-d'), $sunday->format('Y-m-d'), $timezone);

        return [$this->queryTimestamp($coverage[0]['start']), $this->queryTimestamp($coverage[count($coverage) - 1]['end'])];
    }

    /** Batch validation includes soft-deleted work history, without loading foreign data. */
    public function validateSessionReferences(User $user, Collection $sessions): void
    {
        if ($sessions->isEmpty()) {
            return;
        }
        $childIds = $sessions->pluck('subtask_id')->filter()->unique()->all();
        $tasks = $user->tasks()->withTrashed()->whereKey($sessions->pluck('task_id')->unique()->all())
            ->with(['subtasks' => fn ($query) => $query->withTrashed()->whereKey($childIds)->select(['id', 'task_id'])])
            ->get(['id', 'user_id'])->keyBy('id');
        $children = $tasks->flatMap(fn ($task) => $task->subtasks)->keyBy('id');
        foreach ($sessions as $session) {
            if (! $tasks->has($session->task_id)
                || ($session->subtask_id !== null && (! $children->has($session->subtask_id)
                    || $children[$session->subtask_id]->task_id !== $session->task_id))) {
                throw new UnexpectedValueException('Invalid planning source relationships.');
            }
        }
    }

    /** @return list<array{start: int, end: int}> */
    private function eventIntervals(Collection $events): array
    {
        return $events->map(function ($event): array {
            $interval = [
                'start' => $this->utcSeconds($event->getRawOriginal('starts_at')),
                'end' => $this->utcSeconds($event->getRawOriginal('ends_at')),
            ];
            $this->intervals->durationSeconds($interval);

            return $interval;
        })->values()->all();
    }

    private function calendarDate(string $value): DateTimeImmutable
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
            || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException('Expected a valid calendar date in YYYY-MM-DD format.');
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    }

    public function utcSeconds(mixed $value): int
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Expected a valid whole-second UTC database timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Expected a valid whole-second UTC database timestamp.');
        }
        $this->queryTimestamp($date->getTimestamp());

        return $date->getTimestamp();
    }

    public function queryTimestamp(int $seconds): string
    {
        $value = (new DateTimeImmutable('@'.$seconds))->format('Y-m-d H:i:s');
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value)
            || $value < '1000-01-01 00:00:00' || $value > '9999-12-31 23:59:59') {
            throw new InvalidArgumentException('A timestamp or query boundary is outside the UTC DATETIME range.');
        }

        return $value;
    }
}
