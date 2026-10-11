<?php

namespace App\Http\Requests;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FreeSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        $date = ['bail', 'required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',
            function (string $attribute, mixed $value, Closure $fail): void {
                // Validate calendar components without parsing in the server's local timezone.
                if (! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                    $fail('The '.$attribute.' field must be a valid calendar date.');
                }
            },
        ];

        return [
            'start_date' => $date,
            'end_date' => $date,
            'min_slot_minutes' => ['bail', 'sometimes', 'required', 'string', 'regex:/\A[1-9][0-9]*\z/', 'integer', 'between:1,65535'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('start_date') || $validator->errors()->has('end_date')) {
                return;
            }
            $data = $validator->getData();
            $zone = new DateTimeZone('UTC');
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $data['start_date'], $zone);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $data['end_date'], $zone);
            if ($end < $start) {
                $validator->errors()->add('end_date', 'The end date must not precede the start date.');
            } elseif ($start->diff($end)->days >= 31) {
                $validator->errors()->add('end_date', 'The range must contain at most 31 inclusive calendar dates.');
            }
        }];
    }
}
