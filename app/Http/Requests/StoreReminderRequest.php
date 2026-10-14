<?php

namespace App\Http\Requests;

use App\Services\Reminders\ReminderRules;
use Illuminate\Validation\Validator;

class StoreReminderRequest extends ReminderActionRequest
{
    protected function creating(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_fill_keys(['message', 'trigger_at', 'offset_minutes', ...($this->creating() ? ['anchor', ...ReminderRules::TARGETS] : [])], ['sometimes']);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateJsonBody($validator);
            foreach (app(ReminderRules::class)->errors($validator->getData(), $this->creating()) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }
}
