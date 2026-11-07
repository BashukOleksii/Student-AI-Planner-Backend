<?php

namespace App\Http\Requests\Auth;

use App\Rules\BcryptPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:256'],
            'password' => ['required', 'string', 'max:1024', 'confirmed', Password::defaults(), new BcryptPassword],
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
