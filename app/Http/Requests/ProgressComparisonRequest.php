<?php

namespace App\Http\Requests;

use App\Services\Planning\PlanningContextService;
use App\Services\Progress\ProgressComparisonService;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class ProgressComparisonRequest extends FreeSlotsRequest
{
    public function rules(): array
    {
        $date = parent::rules()['start_date'];

        return array_fill_keys(['baseline_start_date', 'baseline_end_date', 'current_start_date', 'current_end_date'], $date);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $validator->getData();
            foreach (array_diff(array_keys($data), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Це поле не дозволене в цьому запиті.');
            }
            foreach (['baseline', 'current'] as $period) {
                if ($validator->errors()->has($period.'_start_date') || $validator->errors()->has($period.'_end_date')) {
                    continue;
                }
                try {
                    app(PlanningContextService::class)->validateDates($data[$period.'_start_date'], $data[$period.'_end_date']);
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add($period.'_end_date', 'Межі періоду мають бути впорядковані й охоплювати не більш як 31 календарну дату включно.');
                }
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                app(ProgressComparisonService::class)->validatePeriods($data['baseline_start_date'], $data['baseline_end_date'],
                    $data['current_start_date'], $data['current_end_date']);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('current_start_date', match ($exception->getMessage()) {
                    'The baseline must end before the current period begins.' => 'Базовий період має завершитися до початку поточного.',
                    'Both periods must contain the same number of nominal calendar dates.' => 'Обидва періоди мають містити однакову кількість календарних дат.',
                    default => 'Не вдалося порівняти періоди. Перевірте вибрані дати.',
                });
            }
        }];
    }
}
