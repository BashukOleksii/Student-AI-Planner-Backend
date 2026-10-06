<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveEducationInstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('PATCH')) {
            Gate::authorize('update', $this->route('educationInstitution'));
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];
        $unique = Rule::unique('education_institutions', 'name')->where('user_id', $this->user()->id);
        if ($this->isMethod('PATCH')) {
            $unique->ignore($this->route('educationInstitution'));
        }

        return ['name' => [...$presence, 'string', 'max:255', $unique]];
    }
}
