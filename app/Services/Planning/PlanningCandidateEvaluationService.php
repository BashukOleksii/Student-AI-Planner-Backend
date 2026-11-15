<?php

namespace App\Services\Planning;

use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use UnexpectedValueException;

class PlanningCandidateEvaluationService
{
    public function __construct(
        private TimeIntervalService $intervals,
        private PlanningContextService $context,
        private PlanningConstraintService $constraints,
    ) {}

    /**
     * @param  array{start: int, end: int}  $candidate
     * @return list<string>
     *
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     * @throws UnexpectedValueException
     */
    public function evaluate(User $user, array $candidate, mixed $taskId = null, mixed $subtaskId = null): array
    {
        $this->intervals->durationSeconds($candidate);
        $this->context->queryTimestamp($candidate['start']);
        $this->context->queryTimestamp($candidate['end']);
        foreach ([$taskId, $subtaskId] as $id) {
            if ($id !== null && (! is_int($id) || $id < 1)) {
                throw new InvalidArgumentException('Work identifiers must be null or positive integers.');
            }
        }
        if ($subtaskId !== null && $taskId === null) {
            throw new InvalidArgumentException('A subtask requires its parent task identifier.');
        }

        $user = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id', 'timezone']);
        [$startDate, $endDate] = $this->candidateDates($candidate, $user->timezone);
        $deadline = $this->deadline($user, $taskId, $subtaskId);
        $context = $this->context->load($user, $startDate, $endDate, $candidate);

        return $this->constraints->evaluate(
            $candidate, $context['free_intervals'], $context['break_anchors'],
            $context['workload_sessions'], $user->timezone, $context['preferences'], $deadline,
        );
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
        $deadlines = array_map(fn ($value): int => $this->context->utcSeconds($value), array_values(array_filter($values, fn ($value): bool => $value !== null)));

        return $deadlines === [] ? null : min($deadlines);
    }
}
