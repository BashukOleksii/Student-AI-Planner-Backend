<?php

namespace App\Http\Requests;

use App\Services\Schedule\EffectiveScheduleCalculator;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class AcademicCalendarDateRequest extends ScheduleDateRequest
{
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('date')) {
                return;
            }
            try {
                // Also reject week labels whose Monday/Sunday cannot be represented.
                if ($this->routeIs('academic-calendar.week')) {
                    app(EffectiveScheduleCalculator::class)->weekDates($this->input('date'));
                } else {
                    app(EffectiveScheduleCalculator::class)->validateDate($this->input('date'));
                }
            } catch (InvalidArgumentException) {
                $validator->errors()->add('date', 'The value must be a supported calendar date.');
            }
        }];
    }
}
