<?php

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        $integer = ['bail', 'sometimes', 'required', 'regex:/\A[1-9][0-9]*\z/', 'integer', 'min:1'];
        $timestamp = ['bail', 'sometimes', 'required', 'string', 'date_format:Y-m-d\TH:i:s\Z',
            'after_or_equal:1000-01-01T00:00:00Z', 'before_or_equal:9999-12-31T23:59:59Z'];

        return [
            'status' => ['sometimes', 'required', 'string', Rule::enum(TaskStatus::class)],
            'priority' => [...$integer, Rule::enum(TaskPriority::class)],
            'subject_id' => $integer,
            'deadline_from' => $timestamp,
            'deadline_to' => $timestamp,
            'page' => $integer,
            'per_page' => [...$integer, 'max:100'],
        ];
    }
}
