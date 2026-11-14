<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class ReminderActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($id = $this->route('reminder')) {
            $reminder = $this->user()->reminders()->whereKey($id)->firstOrFail();
            Gate::authorize('update', $reminder);
        }

        return true;
    }

    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateJsonBody($validator);
            foreach (array_diff(array_keys($validator->getData()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Це поле не дозволене в цьому запиті.');
            }
        }];
    }

    protected function validateJsonBody(Validator $validator): void
    {
        if ($this->isJson() && $this->getContent() !== '') {
            $body = json_decode($this->getContent());
            // Accept the conventional empty action payload [] as well as {}.
            if (json_last_error() !== JSON_ERROR_NONE || (! $body instanceof \stdClass && $body !== [])) {
                $validator->errors()->add('reminder', 'Потрібен JSON-об’єкт.');
            }
        }
    }
}
