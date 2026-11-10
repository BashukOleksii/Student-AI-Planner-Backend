<?php

namespace App\Http\Requests;

use App\Services\Schedule\EffectiveScheduleCalculator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreSemesterScheduleImportRequest extends StoreScheduleImportRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'parity_anchor_on' => ['required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d'],
            'first_week_parity' => ['required', Rule::in(['numerator', 'denominator'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('parity_anchor_on')) {
                return;
            }
            try {
                $date = $this->input('parity_anchor_on');
                $calculator = app(EffectiveScheduleCalculator::class);
                $calculator->validateDate($date);
                if ($calculator->weekDates($date)[0] !== $date) {
                    throw new InvalidArgumentException;
                }
            } catch (InvalidArgumentException) {
                $validator->errors()->add('parity_anchor_on', 'The parity anchor must be a valid Monday.');
            }
        }];
    }
}
