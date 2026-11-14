<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class EmailVerificationNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['email' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['email.prohibited' => 'Адреса для підтвердження береться з вашого профілю.'];
    }
}
