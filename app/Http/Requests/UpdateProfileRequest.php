<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user())],
            'timezone' => ['sometimes', 'required', 'string', 'max:64', 'timezone'],
            'reminder_email_enabled' => ['sometimes', 'required', 'boolean:strict'],
            'session_reminder_in_app_enabled' => ['sometimes', 'required', 'boolean:strict'],
            'session_reminder_email_enabled' => ['sometimes', 'required', 'boolean:strict'],
            'session_reminder_lead_minutes' => ['sometimes', 'required', 'integer:strict', 'between:1,65535'],
        ];
    }
}
