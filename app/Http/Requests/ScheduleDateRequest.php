<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['date' => $this->route('date')]);
    }

    public function rules(): array
    {
        return ['date' => ['bail', 'required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d']];
    }
}
