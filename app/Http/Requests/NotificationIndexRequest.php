<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class NotificationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        $integer = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/\A[1-9][0-9]*\z/', $value)) {
                $fail('Use a positive decimal integer.');
            }
        };

        return ['unread_only' => ['sometimes', 'required', Rule::in(['true', 'false'])],
            'per_page' => ['sometimes', 'bail', $integer, 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'bail', $integer, 'integer', 'min:1', 'max:2147483647']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($validator->getData()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This filter is not accepted.');
            }
        }];
    }
}
