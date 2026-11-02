<?php

namespace App\Http\Requests;

use App\Models\Lesson;
use App\Services\Schedule\LessonInputRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route('lesson') instanceof Lesson) {
            Gate::authorize('update', $this->route('lesson'));
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return LessonInputRules::rules($this->isMethod('POST'));
    }
}
