<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class SaveSemesterScheduleRequest extends SemesterScheduleManagementRequest
{
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [...parent::rules(),
            'academic_period_id' => $this->isMethod('POST') ? ['required', 'integer:strict', 'min:1'] : ['prohibited'],
            'group_name' => [...$presence, 'string', 'max:255'],
            'parity_anchor_on' => [$this->isMethod('POST') ? 'required' : 'sometimes', ...$this->dateRules()],
            'first_week_parity' => [...$presence, Rule::in(['numerator', 'denominator'])],
        ];
    }
}
