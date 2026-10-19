<?php

namespace App\Http\Requests;

use App\Models\Subtask;
use App\Services\Tasks\SubtaskInputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveSubtaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('task'));
        if ($this->route('subtask') instanceof Subtask) {
            Gate::authorize('update', [$this->route('subtask'), $this->route('task')]);
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return SubtaskInputRules::rules($this->isMethod('POST'), $this->all());
    }
}
