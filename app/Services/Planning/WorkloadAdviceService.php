<?php

namespace App\Services\Planning;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** On-demand advice from the authoritative greedy result, never a persistence preview. */
class WorkloadAdviceService
{
    private const ALLOCATION_CODES = [
        'no_available_study_time', 'deadline_capacity_exhausted',
        'daily_study_limit_exceeded', 'weekly_study_limit_exceeded',
        'below_minimum_session_duration', 'above_maximum_session_duration', 'outside_available_study_time',
        'overlapping_break_anchor', 'insufficient_break_before', 'insufficient_break_after',
        'deadline_exceeded', 'planning_horizon_exhausted',
    ];

    public function __construct(
        private StudyPlanningService $planning,
        private PlanningContextService $context,
        private WorkloadConstraintService $workload,
    ) {}

    public function analyze(User $user, string $startDate, string $endDate): array
    {
        return DB::transaction(function () use ($user, $startDate, $endDate): array {
            $owner = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id', 'timezone']);
            $plan = $this->planning->plan($owner, $startDate, $endDate);
            $work = [];
            foreach ($plan['work_results'] as $index => $item) {
                $work[] = [...$item, 'rank' => $index + 1,
                    'recommendation_codes' => $this->recommendations($item['reason_codes'], $item['unscheduled_seconds'] > 0)];
            }
            $excluded = array_map(fn ($item) => [...$item,
                'recommendation_codes' => $this->excludedRecommendations($item['reason_codes'])], $plan['excluded_items']);
            $violations = $this->persistedViolations($owner, $startDate, $endDate);
            $alerts = [];
            if (! $plan['summary']['fully_allocated']) {
                $affected = array_filter($work, fn ($item) => $item['unscheduled_seconds'] > 0);
                $alerts[] = $this->alert('unscheduled_work', 'allocation', $plan['summary']['unscheduled_seconds'], count($affected));
            }
            foreach (self::ALLOCATION_CODES as $code) {
                $affected = array_filter($work, fn ($item) => in_array($code, $item['reason_codes'], true));
                if ($affected !== []) {
                    $alerts[] = $this->alert($code, 'allocation', array_sum(array_column($affected, 'unscheduled_seconds')), count($affected));
                }
            }
            if ($excluded !== []) {
                // Excluded work can have an unknown estimate; never invent a duration.
                $alerts[] = $this->alert('excluded_work', 'selection', null, count($excluded));
            }
            foreach (['daily_study_limit_exceeded', 'weekly_study_limit_exceeded'] as $code) {
                $affected = array_filter($violations, fn ($item) => $item['code'] === $code);
                if ($affected !== []) {
                    $alerts[] = $this->alert($code, 'persisted_workload', array_sum(array_column($affected, 'excess_seconds')), 0);
                }
            }

            return ['as_of_utc' => $plan['as_of_utc'], 'timezone' => $plan['timezone'],
                'period' => ['start_date' => $plan['start_date'], 'end_date' => $plan['end_date']],
                'summary' => $plan['summary'], 'alerts' => $alerts, 'work_advice' => $work,
                'excluded_items' => $excluded, 'warnings' => $plan['warnings'], 'persisted_workload_violations' => $violations];
        });
    }

    private function persistedViolations(User $owner, string $startDate, string $endDate): array
    {
        $context = $this->context->load($owner, $startDate, $endDate);
        $result = [];
        foreach ($this->context->dateIntervals($startDate, $endDate, $owner->timezone) as $range) {
            foreach ($this->workload->existingViolations($range, $context['workload_sessions'], $owner->timezone,
                $context['preferences']['max_daily_study_minutes'], $context['preferences']['max_weekly_study_minutes']) as $item) {
                // Repeated local dates or disjoint date membership must not duplicate a period.
                $result[$item['code'].':'.$item['period']] = $item;
            }
        }
        ksort($result);

        return array_values($result);
    }

    private function alert(string $code, string $source, ?int $seconds, int $count): array
    {
        return ['code' => $code, 'severity' => $source === 'selection' ? 'info' : 'warning',
            'source' => $source, 'affected_seconds' => $seconds, 'affected_work_count' => $count];
    }

    private function recommendations(array $reasons, bool $unscheduled): array
    {
        $result = ['follow_planning_order'];
        if (! $unscheduled) {
            return $result;
        }
        $result = [...$result, 'review_planning_period', 'consider_recovery_preview'];
        $mapping = [
            'review_study_availability' => ['no_available_study_time', 'outside_available_study_time'],
            'review_deadline' => ['deadline_capacity_exhausted', 'deadline_exceeded'],
            'review_study_limits' => ['daily_study_limit_exceeded', 'weekly_study_limit_exceeded'],
            'review_session_duration' => ['below_minimum_session_duration', 'above_maximum_session_duration'],
            'review_break_preferences' => ['overlapping_break_anchor', 'insufficient_break_before', 'insufficient_break_after'],
        ];
        foreach ($mapping as $code => $evidence) {
            if (array_intersect($reasons, $evidence) !== []) {
                $result[] = $code;
            }
        }

        return $result;
    }

    private function excludedRecommendations(array $reasons): array
    {
        if (array_intersect($reasons, ['completed_task', 'parent_task_completed', 'completed_subtask',
            'completed_effort_satisfies_estimate', 'fully_reserved', 'subtasks_authoritative']) !== []) {
            return [];
        }
        $result = [];
        if (in_array('missing_estimate', $reasons, true)) {
            $result[] = 'supply_estimated_effort';
        }
        if (in_array('deadline_expired', $reasons, true)) {
            $result[] = 'review_deadline';
        }

        return $result;
    }
}
