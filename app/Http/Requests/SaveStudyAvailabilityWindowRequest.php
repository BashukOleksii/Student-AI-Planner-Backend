<?php

namespace App\Http\Requests;

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
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'day_of_week' => [$presence, 'required', 'integer:strict', 'between:1,7'],
            'starts_at' => [$presence, 'required', 'string', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/'],
            'ends_at' => [$presence, 'required', 'string', 'regex:/\A(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]|24:00:00)\z/'],
        ];
    }
}
