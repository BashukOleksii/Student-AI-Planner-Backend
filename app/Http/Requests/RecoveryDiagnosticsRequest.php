<?php

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

class RecoveryDiagnosticsRequest extends StudySessionRangeRequest
{
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            foreach (['start_date', 'end_date'] as $field) {
                if (! $validator->errors()->has($field) && $this->input($field) < '1000-01-01') {
                    $validator->errors()->add($field, 'Укажіть підтримувану календарну дату.');
                }
            }
        }];
    }
}
