<?php

namespace App\Http\Requests;

use App\Services\Planning\StudySessionLifecycleRules;
use Closure;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class RescheduleStudySessionRequest extends StudySessionActionRequest
{
    public function rules(): array
    {
        $timestamp = ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                app(StudySessionLifecycleRules::class)->timestamp($value);
            } catch (InvalidArgumentException) {
                $fail('The '.$attribute.' field must be a real whole-second UTC timestamp in YYYY-MM-DDTHH:MM:SSZ format, within years 1000–9999.');
            }
        }];

        return ['starts_at' => $timestamp, 'ends_at' => $timestamp];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->has('starts_at') || $validator->errors()->has('ends_at')) {
                return;
            }
            $rules = app(StudySessionLifecycleRules::class);
            $data = $validator->getData();
            if ($rules->timestamp($data['ends_at']) <= $rules->timestamp($data['starts_at'])) {
                $validator->errors()->add('ends_at', 'The ends at field must be after starts at.');
            }
        }];
    }
}
