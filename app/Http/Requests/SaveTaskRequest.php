<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Services\Tasks\TaskInputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

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
        return TaskInputRules::rules($this->isMethod('POST'), $this->all());
    }
}
