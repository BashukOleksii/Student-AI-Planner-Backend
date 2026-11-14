<?php

namespace App\Http\Requests;

use App\Services\Schedule\EffectiveScheduleCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class SemesterScheduleManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $schedule = $this->route('semesterSchedule');
        if ($schedule !== null) {
            Gate::authorize($this->isMethod('GET') ? 'view' : 'update', $schedule);
            if ($this->route('weeklyRule') !== null) {
                abort_unless($schedule->weeklyLessonRules()->where('user_id', $this->user()->id)->whereKey($this->route('weeklyRule'))->exists(), 404);
            }
            if ($this->route('suppressedSlot') !== null) {
                abort_unless($schedule->suppressedSlots()->where('user_id', $this->user()->id)->whereKey($this->route('suppressedSlot'))->exists(), 404);
            }
        }

        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('date') !== null) {
            $this->merge(['date' => $this->route('date')]);
        }
    }

    public function rules(): array
    {
        $rules = [];
        if (! $this->isMethod('GET') && $this->route('semesterSchedule') !== null) {
            $rules['schedule_token'] = ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'];
        }
        if ($this->isMethod('DELETE')) {
            $rules['confirmed'] = ['required', 'boolean:strict', 'accepted'];
        }
        if ($this->route('date') !== null) {
            $rules['date'] = $this->dateRules();
        }

        return $rules;
    }

    protected function dateRules(): array
    {
        return ['bail', 'required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['date', 'occurs_on', 'parity_anchor_on'] as $field) {
                if (! array_key_exists($field, $this->rules()) || ! $this->exists($field) || $validator->errors()->has($field)) {
                    continue;
                }
                try {
                    $date = $this->input($field);
                    $calculator = app(EffectiveScheduleCalculator::class);
                    $calculator->validateDate($date);
                    if ($field === 'parity_anchor_on' && $calculator->weekDates($date)[0] !== $date) {
                        throw new InvalidArgumentException;
                    }
                } catch (InvalidArgumentException) {
                    $validator->errors()->add($field, 'Укажіть коректну календарну дату; початок відліку парності має припадати на понеділок.');
                }
            }
        }];
    }
}
