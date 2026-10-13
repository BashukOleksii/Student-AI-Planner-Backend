<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StudySessionActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Resolve ownership before validating action fields; the Service reloads under locks.
        $session = $this->user()->studySessions()->whereKey($this->route('studySession'))->firstOrFail();
        Gate::authorize('update', $session);

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
            foreach (array_diff(array_keys($validator->getData()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        }];
    }
}
