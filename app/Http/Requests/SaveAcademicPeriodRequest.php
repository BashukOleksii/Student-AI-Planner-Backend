<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveAcademicPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('PATCH')) {
            Gate::authorize('update', $this->route('academicPeriod'));
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'education_institution_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'name' => [...$presence, 'string', 'max:255'],
            'starts_on' => [...$presence, 'string', 'date_format:Y-m-d'],
            'ends_on' => [...$presence, 'string', 'date_format:Y-m-d'],
        ];
    }
}
