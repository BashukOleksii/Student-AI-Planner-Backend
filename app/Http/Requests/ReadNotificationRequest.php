<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class ReadNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notification = $this->user()->notifications()->whereKey($this->route('notification'))->firstOrFail();
        Gate::authorize('update', $notification);

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
            foreach (array_keys($validator->getData()) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            if ($this->isJson() && $this->getContent() !== '') {
                $body = json_decode($this->getContent());
                if (json_last_error() !== JSON_ERROR_NONE || (! $body instanceof \stdClass && $body !== [])) {
                    $validator->errors()->add('notification', 'A JSON object is required.');
                }
            }
        }];
    }
}
