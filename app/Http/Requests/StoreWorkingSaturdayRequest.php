<?php

namespace App\Http\Requests;

class StoreWorkingSaturdayRequest extends SemesterScheduleManagementRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'occurs_on' => $this->dateRules(),
            'copied_weekday' => ['required', 'integer:strict', 'between:1,5'],
        ];
    }
}
