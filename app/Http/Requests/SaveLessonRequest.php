<?php

namespace App\Http\Requests;

use App\Enums\LessonType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->isMethod('PATCH')) {
            Gate::authorize('update', $this->route('lesson'));
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? ['required'] : ['sometimes', 'required'];
        $timestamp = ['bail', ...$presence, 'string', 'date_format:Y-m-d\TH:i:s\Z',
            'after_or_equal:1000-01-01T00:00:00Z', 'before_or_equal:9999-12-31T23:59:59Z'];

        return [
            'subject_id' => [...$presence, 'integer:strict', 'min:1'],
            'teacher_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'academic_period_id' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
            'type' => [...$presence, 'string', Rule::enum(LessonType::class)],
            'room' => ['sometimes', 'nullable', 'string', 'max:100'],
            'starts_at' => $timestamp,
            'ends_at' => $timestamp,
        ];
    }
}
