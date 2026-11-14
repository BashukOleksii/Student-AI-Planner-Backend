<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->hasSession()) {
            throw ValidationException::withMessages([
                'session' => ['Сеанс застосунку відсутній. Оновіть сторінку та увійдіть знову.'],
            ]);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'timezone' => ['sometimes', 'required', 'string', 'max:64', 'timezone'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'ім’я'];
    }
}
