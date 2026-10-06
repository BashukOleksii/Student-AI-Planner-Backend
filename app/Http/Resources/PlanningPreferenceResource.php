<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanningPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'max_daily_study_minutes' => $this->max_daily_study_minutes,
            'max_weekly_study_minutes' => $this->max_weekly_study_minutes,
            'preferred_break_minutes' => $this->preferred_break_minutes,
            'min_session_minutes' => $this->min_session_minutes,
            'max_session_minutes' => $this->max_session_minutes,
        ];
    }
}
