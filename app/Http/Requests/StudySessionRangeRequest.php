<?php

namespace App\Http\Requests;

use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/** Share PF1 exact calendar/range validation; GET reads only query filters. */
class StudySessionRangeRequest extends FreeSlotsRequest
{
    public function rules(): array
    {
        return Arr::only(parent::rules(), ['start_date', 'end_date']);
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            foreach (array_diff(array_keys($validator->getData()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        }];
    }
}
