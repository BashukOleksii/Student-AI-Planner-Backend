<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Validation\Validator;

class SendAiMessageRequest extends AiConversationRequest
{
    public function rules(): array
    {
        return ['content' => ['bail', 'required', 'string', function (string $field, mixed $value, Closure $fail): void {
            if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 4000
                || preg_match('/\A[\s\p{Z}\p{C}]*\z/u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                $fail('Введіть змістовне повідомлення UTF-8 до 4000 символів.');
            }
        }]];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (! is_string($this->header('Idempotency-Key')) || ! preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}\z/', $this->header('Idempotency-Key'))) {
                $validator->errors()->add('idempotency_key', 'Потрібен заголовок Idempotency-Key із UUID. Оновіть сторінку та повторіть дію.');
            }
        }];
    }
}
