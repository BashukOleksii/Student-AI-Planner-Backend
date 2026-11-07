<?php

namespace App\Http\Requests\Auth;

use App\Rules\BcryptPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->hasSession()) {
            throw ValidationException::withMessages(['session' => ['A SPA session is required. Send an Origin or Referer from a configured frontend.']]);
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'max:1024', 'confirmed', Password::defaults(), new BcryptPassword],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
