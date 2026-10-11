<?php

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Models\Task;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route('task') instanceof Task) {
            Gate::authorize('update', $this->route('task'));
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];

        return [
            'title' => [...$presence, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                // MySQL TEXT capacity is measured in bytes, including UTF-8 text.
                if (is_string($value) && strlen($value) > 65535) {
                    $fail('The description must not exceed 65535 bytes.');
                }
            }],
            'subject_id' => ['sometimes', 'nullable', Rule::when($this->input('subject_id') !== null, ['required']), 'integer:strict', 'min:1'],
            'priority' => ['sometimes', 'required', 'integer:strict', Rule::enum(TaskPriority::class)],
            'estimated_minutes' => ['sometimes', 'nullable', Rule::when($this->input('estimated_minutes') !== null, ['required']), 'integer:strict', 'between:1,65535'],
            'deadline_at' => ['bail', 'sometimes', 'nullable', Rule::when($this->input('deadline_at') !== null, ['required']), 'string', 'date_format:Y-m-d\TH:i:s\Z',
                'after_or_equal:1000-01-01T00:00:00Z', 'before_or_equal:9999-12-31T23:59:59Z'],
        ];
    }
}
