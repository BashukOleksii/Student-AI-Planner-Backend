<?php

namespace App\Http\Requests;

use App\Enums\ReminderStatus;
use App\Services\Reminders\ReminderRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class ReminderIndexRequest extends FormRequest
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
        $time = ['sometimes', 'bail', 'required', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                app(ReminderRules::class)->timestamp($value);
            } catch (InvalidArgumentException) {
                $fail('Use a real whole-second UTC timestamp within years 1000–9999.');
            }
        }];
        $integer = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/\A[1-9][0-9]*\z/', $value)) {
                $fail('Use a positive decimal integer.');
            }
        };

        return ['status' => ['sometimes', 'required', Rule::enum(ReminderStatus::class)],
            'trigger_from' => $time, 'trigger_to' => $time,
            'per_page' => ['sometimes', 'bail', $integer, 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'bail', $integer, 'integer', 'min:1', 'max:2147483647']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $validator->getData();
            foreach (array_diff(array_keys($data), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This filter is not accepted.');
            }
            if (array_key_exists('trigger_from', $data) !== array_key_exists('trigger_to', $data)) {
                $validator->errors()->add('trigger_to', 'Supply both trigger range bounds.');
            }
            if ($validator->errors()->isEmpty() && isset($data['trigger_from'], $data['trigger_to'])) {
                $rules = app(ReminderRules::class);
                $span = $rules->timestamp($data['trigger_to']) - $rules->timestamp($data['trigger_from']);
                if ($span < 0 || $span > 366 * 86400) {
                    $validator->errors()->add('trigger_to', 'The inclusive range must be ordered and span at most 366 days.');
                }
            }
        }];
    }
}
