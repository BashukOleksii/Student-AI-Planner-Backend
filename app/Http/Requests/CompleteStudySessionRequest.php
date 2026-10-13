<?php

namespace App\Http\Requests;

class CompleteStudySessionRequest extends StudySessionActionRequest
{
    public function rules(): array
    {
        return ['actual_minutes' => ['bail', 'sometimes', 'required', 'integer:strict', 'min:1', 'max:65535']];
    }
}
