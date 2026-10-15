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
            $metrics = $this->activityMetrics($this->loadActivity($owner, $coverage), $coverage);

            return [
                'as_of_utc' => $now->format('Y-m-d\TH:i:s.u\Z'),
                'timezone' => $owner->timezone,
                'period' => ['start_date' => $startDate, 'end_date' => $endDate, 'session_attribution' => 'starts_at'],
                'tasks' => ['current' => $taskCurrent, 'completed_in_period' => $metrics['tasks_completed']],
                'subtasks' => ['current' => $childCurrent, 'completed_in_period' => $metrics['subtasks_completed']],
                'study_sessions' => ['started_in_period' => $metrics['study_sessions']],
            ];
        });
    }

    /** Shared G1/G2a bounded sources; caller supplies the fresh owner and read transaction. */
    public function loadActivity(User $owner, array $coverage): array
    {
        $tasks = $this->completions($owner->tasks()->getQuery(), $coverage);
        $children = $this->completions(Subtask::query()->whereHas('task',
            fn ($query) => $query->where('user_id', $owner->getKey())), $coverage);
        $sessions = $this->inPeriod($owner->studySessions()->getQuery(), 'starts_at', $coverage)
            ->get(['id', 'task_id', 'subtask_id', 'status', 'starts_at', 'ends_at', 'actual_minutes']);
        $this->context->validateSessionReferences($owner, $sessions);

        return ['tasks' => $tasks, 'subtasks' => $children,
            'study_sessions' => $sessions->map(fn ($session) => $session->getAttributes())->all()];
    }

    /** Pure shared activity definitions, without lifetime current-state counts. */
    public function activityMetrics(array $activity, array $coverage): array
    {
        return [
            'tasks_completed' => $this->completionMetrics($activity['tasks'], $coverage),
            'subtasks_completed' => $this->completionMetrics($activity['subtasks'], $coverage),
            'study_sessions' => $this->sessionMetrics($activity['study_sessions'], $coverage),
        ];
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

        return $records->map(fn ($record) => $record->getAttributes())->all();
    }

    /** Pure reduction of raw UTC persistence records; parent deadlines are never substituted. */
    public function completionMetrics(iterable $records, array $coverage): array
    {
        $result = ['total' => 0, 'on_time' => 0, 'late' => 0, 'without_deadline' => 0];
        foreach ($records as $record) {
            $completed = $this->completionInstant($record);
            if ($completed === null) {
                continue;
            }
            if (! $this->contains($coverage, $completed)) {
                continue;
            }
            $deadline = $record['deadline_at'] === null ? null : $this->context->utcSeconds($record['deadline_at']);
            $result['total'] = $this->add($result['total'], 1);
            $key = $deadline === null ? 'without_deadline' : ($completed <= $deadline ? 'on_time' : 'late');
            $result[$key] = $this->add($result[$key], 1);
        }

        return $result;
    }

    public function completionInstant(array $record): ?int
    {
        return $record['status'] === TaskStatus::Completed->value
            ? $this->context->utcSeconds($record['completed_at']) : null;
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
            $result[$status]['count'] = $this->add($result[$status]['count'], 1);
            $result[$status]['scheduled_seconds'] = $this->add($result[$status]['scheduled_seconds'], $duration);
            if ($status === StudySessionStatus::Completed->value) {
                if ($record['actual_minutes'] === null) {
                    $result['completed_without_actual_minutes'] = $this->add($result['completed_without_actual_minutes'], 1);
                } else {
                    if (! is_int($record['actual_minutes']) || $record['actual_minutes'] < 1 || $record['actual_minutes'] > 65535) {
                        throw new UnexpectedValueException('Invalid statistics source.');
                    }
                    $result['completed_with_actual_minutes'] = $this->add($result['completed_with_actual_minutes'], 1);
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

    public function contains(array $coverage, int $instant): bool
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
