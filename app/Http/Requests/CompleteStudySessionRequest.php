<?php

namespace App\Http\Requests;

class CompleteStudySessionRequest extends StudySessionActionRequest
{
    public function rules(): array
    {
        return ['complete_work' => ['sometimes', 'boolean:strict'], 'completion_token' => ['sometimes', 'string'], 'actual_minutes' => ['bail', 'required', 'integer:strict', 'min:1', 'max:65535']];
    }

    public function messages(): array
    {
        return ['actual_minutes.required' => 'Вкажіть фактичну тривалість навчання.',
            'actual_minutes.integer' => 'Фактична тривалість має бути цілим числом.',
            'actual_minutes.min' => 'Фактична тривалість має бути більшою за нуль.',
            'actual_minutes.max' => 'Фактична тривалість не може перевищувати 65535 хвилин.'];
    }
}
