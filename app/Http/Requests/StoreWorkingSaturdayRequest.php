<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreWorkingSaturdayRequest extends SemesterScheduleManagementRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'occurs_on' => $this->dateRules(),
            'copied_weekday' => ['required', 'integer:strict', 'between:1,5'],
            'source_week_parity' => ['required_with:selected_weekly_rule_ids', Rule::in(['numerator', 'denominator'])],
            'selected_weekly_rule_ids' => ['required_with:source_week_parity', 'array', 'list', 'min:1', 'max:255'],
            'selected_weekly_rule_ids.*' => ['required', 'integer:strict', 'min:1', 'distinct:strict'],
        ];
    }
}
