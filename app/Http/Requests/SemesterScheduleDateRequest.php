<?php

namespace App\Http\Requests;

use App\Services\Schedule\EffectiveScheduleCalculator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class SemesterScheduleDateRequest extends ScheduleDateRequest
{
    public function authorize(): bool
    {
        Gate::authorize('view', $this->route('semesterSchedule'));

        return parent::authorize();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('date')) {
                return;
            }
            try {
                $calculator = app(EffectiveScheduleCalculator::class);
                $calculator->validateDate($this->input('date'));
                if ($this->route()->getActionMethod() === 'week') {
                    $calculator->weekDates($this->input('date'));
                }
            } catch (InvalidArgumentException) {
                $validator->errors()->add('date', 'The date must define a supported calendar interval.');
            }
        }];
    }
}
