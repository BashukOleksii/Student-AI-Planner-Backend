<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReorderSubtasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('task'));

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Present permits [] for an empty task. JSON objects must not masquerade as lists.
            'subtask_ids' => ['bail', 'present', 'array', 'list', 'max:65535', function (string $attribute, mixed $value, Closure $fail): void {
                $payload = json_decode($this->getContent());
                if (! $this->isJson() || ! is_object($payload) || ! is_array($payload->subtask_ids ?? null)) {
                    $fail('The subtask ids must be a JSON array.');
                }
            }],
            'subtask_ids.*' => ['bail', 'required', 'integer:strict', 'min:1', 'distinct:strict'],
        ];
    }
}
