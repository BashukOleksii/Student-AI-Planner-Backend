<?php

namespace App\Services\Planning;

use App\Enums\LessonStatus;
use App\Enums\StudySessionStatus;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use UnexpectedValueException;

/** Read-only assembly of trusted persisted inputs for C8 candidate diagnostics. */
class PlanningCandidateEvaluationService
{
    private const PREFERENCE_FIELDS = [
        'min_session_minutes', 'max_session_minutes', 'preferred_break_minutes',
        'max_daily_study_minutes', 'max_weekly_study_minutes',
    ];

    public function __construct(
        private TimeIntervalService $intervals,
        private AvailabilityOccurrenceService $availability,
        private FreeTimeService $freeTime,
        private PlanningConstraintService $constraints,
    ) {}

    /**
     * @param  array{start: int, end: int}  $candidate  Whole-second half-open UTC interval.
     * @return list<string> Unchanged ordered C8 violation codes.
     *
     * @throws InvalidArgumentException For malformed arguments/configuration/timestamps or unsafe query bounds.
     * @throws ModelNotFoundException For missing, foreign or deleted work targets.
     * @throws UnexpectedValueException For incompatible selected session work references (without identifying details).
     */
    public function evaluate(User $user, array $candidate, mixed $taskId = null, mixed $subtaskId = null): array
    {
        $this->intervals->durationSeconds($candidate);
        $this->queryTimestamp($candidate['start']);
        $this->queryTimestamp($candidate['end']);
        foreach ([$taskId, $subtaskId] as $id) {
            if ($id !== null && (! is_int($id) || $id < 1)) {
                throw new InvalidArgumentException('Work identifiers must be null or positive integers.');
            }
        }
        if ($subtaskId !== null && $taskId === null) {
            throw new InvalidArgumentException('A subtask requires its parent task identifier.');
        }
        // Reload the trusted identity's persisted timezone; ignore cached attributes/relationships.
        $user = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id', 'timezone']);
        [$startDate, $endDate] = $this->candidateDates($candidate, $user->timezone);
        $deadline = $this->deadline($user, $taskId, $subtaskId);
        $row = $user->planningPreference()->first(self::PREFERENCE_FIELDS);
        $preferences = $row?->only(self::PREFERENCE_FIELDS) ?? array_fill_keys(self::PREFERENCE_FIELDS, null);

        return $this->evaluateInputs($user, $candidate, $startDate, $endDate, $preferences, $deadline);
    }

    private function evaluateInputs(User $user, array $candidate, string $startDate, string $endDate, array $preferences, ?int $deadline): array
    {
        [$weekStart, $weekEnd] = $this->workloadBounds($startDate, $endDate, $user->timezone);
        $break = $preferences['preferred_break_minutes'];
        if ($break !== null && (! is_int($break) || $break < 1 || $break > 65535)) {
            throw new InvalidArgumentException('The configured break must be null or integer minutes between 1 and 65535.');
        }
        $buffer = ($break ?? 0) * 60;
        if ($candidate['start'] < PHP_INT_MIN + $buffer || $candidate['end'] > PHP_INT_MAX - $buffer) {
            throw new InvalidArgumentException('The break query envelope exceeds the integer range.');
        }
        $anchorStart = $this->queryTimestamp($candidate['start'] - $buffer);
        $anchorEnd = $this->queryTimestamp($candidate['end'] + $buffer);

        $workload = $user->studySessions()->whereIn('status', [StudySessionStatus::Planned, StudySessionStatus::Completed])
            ->where('starts_at', '<', $weekEnd)->where('ends_at', '>', $weekStart)
            ->get(['id', 'task_id', 'subtask_id', 'starts_at', 'ends_at']);
        $sessions = $user->studySessions()->where('status', StudySessionStatus::Planned)
            ->where('starts_at', '<', $anchorEnd)->where('ends_at', '>', $anchorStart)
            ->get(['id', 'task_id', 'subtask_id', 'starts_at', 'ends_at']);
        $this->validateSessionReferences($user, $workload->concat($sessions)->unique('id'));
        $lessons = $user->lessons()->where('status', LessonStatus::Active)
            ->where('starts_at', '<', $anchorEnd)->where('ends_at', '>', $anchorStart)
            ->get(['starts_at', 'ends_at']);

        $free = $this->freeTime->calculate($user, $startDate, $endDate, null);

        return $this->constraints->evaluate(
            $candidate, $free, $this->eventIntervals($lessons->concat($sessions)),
            $this->eventIntervals($workload), $user->timezone, $preferences, $deadline,
        );
    }

    /** Full local ISO-week membership, without resolving ambiguous/skipped local midnights. */
    private function workloadBounds(string $startDate, string $endDate, string $timezone): array
    {
        $first = $this->calendarDate($startDate);
        $last = $this->calendarDate($endDate);
        $monday = $first->modify('-'.((int) $first->format('N') - 1).' days');
        $sunday = $last->modify('+'.(7 - (int) $last->format('N')).' days');
        $windows = array_map(fn (int $day): array => [
            'day_of_week' => $day, 'starts_at' => '00:00:00', 'ends_at' => '24:00:00',
        ], range(1, 7));
        $coverage = $this->availability->expand($windows, $monday->format('Y-m-d'), $sunday->format('Y-m-d'), $timezone);

        return [$this->queryTimestamp($coverage[0]['start']), $this->queryTimestamp($coverage[count($coverage) - 1]['end'])];
    }

    /** Batch validation includes soft-deleted work history, without loading foreign data. */
    private function validateSessionReferences(User $user, Collection $sessions): void
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

    /** @return array{string, string} */
    private function candidateDates(array $candidate, string $timezone): array
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)
            && ! (str_starts_with($timezone, 'Etc/') && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))) {
            throw new InvalidArgumentException('Expected an IANA timezone name.');
        }
        $zone = new DateTimeZone($timezone);
        $points = [$candidate['start'], $candidate['end'] - 1];
        // Include labels on both sides of offset changes, even historical date-line reversals.
        foreach ($zone->getTransitions($candidate['start'], $candidate['end']) ?: [] as $transition) {
            $points[] = $transition['ts'];
            if ($transition['ts'] > $candidate['start']) {
                $points[] = $transition['ts'] - 1;
            }
        }
        $labels = array_map(fn (int $point): string => (new DateTimeImmutable('@'.$point))->setTimezone($zone)->format('Y-m-d'), $points);
        $start = min($labels);
        $end = max($labels);
        $first = $this->calendarDate($start);
        $last = $this->calendarDate($end);
        if ($first->diff($last)->days >= 31) {
            throw new InvalidArgumentException('A candidate may cover at most 31 inclusive local calendar dates.');
        }

        return [$start, $end];
    }

    private function calendarDate(string $label): DateTimeImmutable
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $label)) {
            throw new InvalidArgumentException('The local calendar date is not representable.');
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $label, new DateTimeZone('UTC'));
    }

    private function deadline(User $user, ?int $taskId, ?int $subtaskId): ?int
    {
        if ($taskId === null) {
            return null;
        }
        $task = $user->tasks()->whereKey($taskId)->firstOrFail(['id', 'deadline_at']);
        $values = [$task->getRawOriginal('deadline_at')];
        if ($subtaskId !== null) {
            $child = $task->subtasks()->whereKey($subtaskId)->firstOrFail(['id', 'deadline_at']);
            $values[] = $child->getRawOriginal('deadline_at');
        }
        $deadlines = array_map(fn ($value): int => $this->utcSeconds($value), array_values(array_filter($values, fn ($value): bool => $value !== null)));

        return $deadlines === [] ? null : min($deadlines);
    }

    private function utcSeconds(mixed $value): int
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

    private function queryTimestamp(int $seconds): string
    {
        $value = (new DateTimeImmutable('@'.$seconds))->format('Y-m-d H:i:s');
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value)
            || $value < '1000-01-01 00:00:00' || $value > '9999-12-31 23:59:59') {
            throw new InvalidArgumentException('A timestamp or query boundary is outside the UTC DATETIME range.');
        }

        return $value;
    }
}
