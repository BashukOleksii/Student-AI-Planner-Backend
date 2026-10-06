<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('PATCH')) {
            Gate::authorize('update', $this->route('subject'));
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$presence, 'string', 'max:255'],
            'education_institution_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'nullable', Rule::when($this->input('color') !== null, ['required']), 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
        ];
    }
}
