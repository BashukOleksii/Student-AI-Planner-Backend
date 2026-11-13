<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecoverySuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }
        if ($this->route('recoverySuggestion') !== null) {
            abort_unless($this->user()->recoverySuggestions()->whereKey($this->route('recoverySuggestion'))->exists(), 404);
        }

        return true;
    }

    public function validationData(): array
    {
        return $this->isMethod('GET') ? $this->query->all() : ($this->isJson() ? $this->json()->all() : $this->request->all());
    }

    public function rules(): array
    {
        if ($this->isMethod('GET') && $this->route('recoverySuggestion') === null) {
            $decimal = function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! preg_match('/\A[1-9][0-9]*\z/', $value)) {
                    $fail('Use a positive decimal integer.');
                }
            };

            return ['status' => ['sometimes', 'required', Rule::in(['all', 'pending', 'applied', 'rejected', 'expired', 'invalidated'])],
                'page' => ['sometimes', 'bail', $decimal, 'integer', 'between:1,1000000'],
                'per_page' => ['sometimes', 'bail', $decimal, 'integer', 'between:1,10']];
        }
        if ($this->routeIs('recovery-suggestions.confirm')) {
            return ['accept_split_replacements' => ['sometimes', 'boolean:strict'], 'accept_partial' => ['sometimes', 'boolean:strict'], 'accept_unplaced_cancellation' => ['sometimes', 'boolean:strict']];
        }

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
