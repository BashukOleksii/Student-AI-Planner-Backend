<?php

namespace App\Http\Resources;

use App\Services\Planning\PlanningExplanationPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plan = $this->resource['plan'];
        $presenter = app(PlanningExplanationPresenter::class);
        $sessions = isset($this->resource['sessions'])
            ? StudySessionResource::collection($this->resource['sessions'])->resolve($request)
            : array_map(fn ($session): array => [
                'task_id' => $session['task_id'], 'subtask_id' => $session['subtask_id'],
                'starts_at' => gmdate('Y-m-d\TH:i:s\Z', $session['start']),
                'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $session['end']), 'duration_seconds' => $session['duration_seconds'],
            ], $plan['sessions']);

        $deadline = fn ($row) => [...$presenter->row($row), 'effective_deadline_at' => isset($row['effective_deadline_utc']) ? gmdate('Y-m-d\TH:i:s\Z', $row['effective_deadline_utc']) : null];

        return ['as_of_utc' => $plan['as_of_utc'], 'start_date' => $plan['start_date'], 'end_date' => $plan['end_date'],
            'timezone' => $plan['timezone'], 'sessions' => $sessions, 'work_results' => array_map($deadline, $plan['work_results']),
            'excluded_items' => array_map($deadline, $plan['excluded_items']), 'warnings' => array_map($presenter->row(...), $plan['warnings']), 'summary' => $plan['summary'],
            'user_summary' => $presenter->summary($plan['summary']),
            'preview_token' => $this->when(isset($this->resource['preview_token']), fn () => $this->resource['preview_token']),
            'expires_at' => $this->when(isset($this->resource['expires_at']), fn () => $this->resource['expires_at'])];
    }
}
