<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
