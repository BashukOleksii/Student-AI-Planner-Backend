<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanningWorkOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'subtask_id' => ['sometimes', 'integer', 'min:1'],
            'task_id' => ['required_with:subtask_id', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'mode' => ['sometimes', Rule::in(['planning', 'replanning'])]];
    }
}
