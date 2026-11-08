<?php

namespace App\Http\Requests;

use App\Services\Planning\StudyAvailabilityInputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveStudyAvailabilityWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('PATCH')) {
            Gate::authorize('update', $this->route('studyAvailabilityWindow'));
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return StudyAvailabilityInputRules::rules($this->isMethod('POST'));
    }
}
