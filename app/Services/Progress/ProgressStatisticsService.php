<?php

namespace App\Services\Progress;

use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Subtask;
use App\Models\User;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\TimeIntervalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/** Read-only current state and bounded persisted activity; no historical reconstruction. */
class ProgressStatisticsService
{
    public function __construct(private PlanningContextService $context, private TimeIntervalService $intervals) {}

    public function calculate(User $user, string $startDate, string $endDate): array
    {
        $this->context->validateDates($startDate, $endDate);

        // Nonlocking consistent reads share the existing MySQL REPEATABLE READ snapshot.
        return DB::transaction(function () use ($user, $startDate, $endDate): array {
            $owner = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id', 'timezone']);
            $now = CarbonImmutable::now('UTC');
            $coverage = $this->context->dateIntervals($startDate, $endDate, $owner->timezone);
            $tasks = $owner->tasks()->getQuery();
            $children = Subtask::query()->whereHas('task', fn ($query) => $query->where('user_id', $owner->getKey()));
            $taskCurrent = $this->currentMetrics(clone $tasks, $now);
            $childCurrent = $this->currentMetrics(clone $children, $now);
            $taskCompletions = $this->completions(clone $tasks, $coverage);
            $childCompletions = $this->completions(clone $children, $coverage);
            $sessionQuery = $this->inPeriod($owner->studySessions()->getQuery(), 'starts_at', $coverage);
            $sessions = $sessionQuery->get(['id', 'task_id', 'subtask_id', 'status', 'starts_at', 'ends_at', 'actual_minutes']);
            // Same owned historical-reference policy as C9 and Stage C session reads.
            $this->context->validateSessionReferences($owner, $sessions);

            return [
                'as_of_utc' => $now->format('Y-m-d\TH:i:s.u\Z'),
                'timezone' => $owner->timezone,
                'period' => ['start_date' => $startDate, 'end_date' => $endDate, 'session_attribution' => 'starts_at'],
                'tasks' => ['current' => $taskCurrent, 'completed_in_period' => $taskCompletions],
                'subtasks' => ['current' => $childCurrent, 'completed_in_period' => $childCompletions],
                'study_sessions' => ['started_in_period' => $this->sessionMetrics(
                    $sessions->map(fn ($session) => $session->getAttributes())->all(), $coverage,
                )],
            ];
        });
    }

    private function currentMetrics(Builder $query, CarbonImmutable $now): array
    {
        $row = $query->selectRaw(
            'COUNT(*) AS total, COALESCE(SUM(status = ?), 0) AS pending, COALESCE(SUM(status = ?), 0) AS completed, '
            .'COALESCE(SUM(status = ? AND deadline_at IS NOT NULL AND deadline_at < ?), 0) AS overdue_pending',
            [TaskStatus::Pending->value, TaskStatus::Completed->value, TaskStatus::Pending->value, $now->format('Y-m-d H:i:s.u')],
        )->toBase()->first();

        return array_map(fn ($value): int => (int) $value, (array) $row);
    }

    private function completions(Builder $query, array $coverage): array
    {
        $records = $this->inPeriod($query->where('status', TaskStatus::Completed), 'completed_at', $coverage)
            ->get(['status', 'completed_at', 'deadline_at']);

        return $this->completionMetrics($records->map(fn ($record) => $record->getAttributes())->all(), $coverage);
    }

    /** Pure reduction of raw UTC persistence records; parent deadlines are never substituted. */
    public function completionMetrics(iterable $records, array $coverage): array
    {
        $result = ['total' => 0, 'on_time' => 0, 'late' => 0, 'without_deadline' => 0];
        foreach ($records as $record) {
            if ($record['status'] !== TaskStatus::Completed->value) {
                continue;
            }
            $completed = $this->context->utcSeconds($record['completed_at']);
            if (! $this->contains($coverage, $completed)) {
                continue;
            }
            $deadline = $record['deadline_at'] === null ? null : $this->context->utcSeconds($record['deadline_at']);
            $result['total']++;
            $result[$deadline === null ? 'without_deadline' : ($completed <= $deadline ? 'on_time' : 'late')]++;
        }

        return $result;
    }

    /** Pure start-attributed full scheduled durations and explicitly known completed actual minutes. */
    public function sessionMetrics(iterable $records, array $coverage): array
    {
        $result = [];
        foreach (StudySessionStatus::cases() as $status) {
            $result[$status->value] = ['count' => 0, 'scheduled_seconds' => 0];
        }
        $result += ['completed_actual_minutes' => 0, 'completed_with_actual_minutes' => 0, 'completed_without_actual_minutes' => 0];
        foreach ($records as $record) {
            $start = $this->context->utcSeconds($record['starts_at']);
            if (! $this->contains($coverage, $start)) {
                continue;
            }
            $duration = $this->intervals->durationSeconds(['start' => $start, 'end' => $this->context->utcSeconds($record['ends_at'])]);
            $status = $record['status'];
            if (StudySessionStatus::tryFrom($status) === null) {
                throw new UnexpectedValueException('Invalid statistics source.');
            }
            $result[$status]['count']++;
            $result[$status]['scheduled_seconds'] = $this->add($result[$status]['scheduled_seconds'], $duration);
            if ($status === StudySessionStatus::Completed->value) {
                if ($record['actual_minutes'] === null) {
                    $result['completed_without_actual_minutes']++;
                } else {
                    if (! is_int($record['actual_minutes']) || $record['actual_minutes'] < 1 || $record['actual_minutes'] > 65535) {
                        throw new UnexpectedValueException('Invalid statistics source.');
                    }
                    $result['completed_with_actual_minutes']++;
                    $result['completed_actual_minutes'] = $this->add($result['completed_actual_minutes'], $record['actual_minutes']);
                }
            }
        }

        return $result;
    }

    private function add(int $total, int $value): int
    {
        if ($total > PHP_INT_MAX - $value) {
            throw new UnexpectedValueException('Statistics exceed the integer range.');
        }

        return $total + $value;
    }

    private function contains(array $coverage, int $instant): bool
    {
        foreach ($coverage as $range) {
            if ($instant >= $range['start'] && $instant < $range['end']) {
                return true;
            }
        }

        return false;
    }

    private function inPeriod(Builder $query, string $column, array $coverage): Builder
    {
        // Dates may be real labels outside the DATETIME storage range. Intersect
        // membership with representable instants rather than sending invalid SQL bounds.
        $minimum = $this->context->utcSeconds('1000-01-01 00:00:00');
        $maximum = $this->context->utcSeconds('9999-12-31 23:59:59');
        $coverage = array_values(array_filter($coverage, fn ($range) => $range['start'] <= $maximum && $range['end'] > $minimum));
        if ($coverage === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($parts) use ($coverage, $column, $minimum, $maximum): void {
            foreach ($coverage as $range) {
                $parts->orWhere(function ($part) use ($range, $column, $minimum, $maximum): void {
                    $part->where($column, '>=', $this->context->queryTimestamp(max($minimum, $range['start'])));
                    if ($range['end'] <= $maximum) {
                        $part->where($column, '<', $this->context->queryTimestamp($range['end']));
                    }
                });
            }
        });
    }
}
