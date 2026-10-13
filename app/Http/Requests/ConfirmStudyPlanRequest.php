<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfirmStudyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function rules(): array
    {
        return ['preview_token' => ['bail', 'required', 'string', 'regex:/\A[A-Za-z0-9+\/=]+\z/'],
            'accept_partial' => ['sometimes', 'required', 'boolean:strict']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($validator->getData()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        }];
    }
}
