<?php

namespace App\Services\Progress;

use App\Enums\StudySessionStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Presentation aggregates only; allocation and planning constraints stay in the planner. */
class WorkloadAnalyticsService
{
    public function __construct(private ProgressStatisticsService $statistics, private ProgressTrendsService $trends) {}

    public function calculate(User $user, string $startDate, string $endDate): array
    {
        return DB::transaction(function () use ($user, $startDate, $endDate): array {
            $progress = $this->statistics->calculate($user, $startDate, $endDate);
            $trends = $this->trends->calculate($user, $startDate, $endDate);
            $completed = $user->studySessions()->where('status', StudySessionStatus::Completed)
                ->selectRaw('COUNT(*) AS sessions, COALESCE(SUM(actual_minutes), 0) AS actual_minutes, SUM(actual_minutes IS NULL) AS without_actual_minutes')
                ->toBase()->first();

            return [
                'as_of_utc' => $progress['as_of_utc'],
                'timezone' => $progress['timezone'],
                'period' => ['start_date' => $startDate, 'end_date' => $endDate],
                // Match statistics: full scheduled duration attributed to the local start date.
                'days' => array_map(fn ($day) => [
                    'date' => $day['date'],
                    'planned_seconds' => $day['study_sessions']['planned']['scheduled_seconds'],
                    'completed_seconds' => $day['study_sessions']['completed']['scheduled_seconds'],
                ], $trends['days']),
                'planned_seconds' => $progress['study_sessions']['started_in_period']['planned']['scheduled_seconds'],
                'planned_sessions' => $progress['study_sessions']['started_in_period']['planned']['count'],
                'subjects' => $progress['subject_workload_in_period'],
                'all_time' => [
                    'completed_actual_minutes' => (int) $completed->actual_minutes,
                    'completed_sessions' => (int) $completed->sessions,
                    'sessions_without_actual_minutes' => (int) $completed->without_actual_minutes,
                    'completed_tasks' => $progress['tasks']['current']['completed'],
                    'completed_subtasks' => $progress['subtasks']['current']['completed'],
                    'task_completion_percent' => $progress['tasks']['current']['completion_percent'],
                ],
            ];
        });
    }
}
