<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanningPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'max_daily_study_minutes' => ['present', 'nullable', 'integer:strict', 'min:1', 'max:65535'],
            'max_weekly_study_minutes' => ['present', 'nullable', 'integer:strict', 'min:1', 'max:65535'],
            'preferred_break_minutes' => ['present', 'nullable', 'integer:strict', 'min:1', 'max:65535'],
            'min_session_minutes' => ['present', 'nullable', 'integer:strict', 'min:1', 'max:65535'],
            'max_session_minutes' => ['present', 'nullable', 'integer:strict', 'min:1', 'max:65535'],
        ];
    }
}
