<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('PATCH')) {
            Gate::authorize('update', $this->route('teacher'));
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$presence, 'string', 'max:255'],
            'education_institution_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
        ];
    }
}
