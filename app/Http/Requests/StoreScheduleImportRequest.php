<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScheduleImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Multipart form fields arrive as strings; accept only canonical integer text.
        $value = $this->input('academic_period_id');
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer !== false) {
                $this->merge(['academic_period_id' => $integer]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'academic_period_id' => ['required', 'integer:strict', 'min:1'],
            'schedule_file' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:5120'],
        ];
    }
}
