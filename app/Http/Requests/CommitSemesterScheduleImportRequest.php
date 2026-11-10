<?php

namespace App\Http\Requests;

class CommitSemesterScheduleImportRequest extends ReviewSemesterScheduleImportRequest
{
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'boolean:strict', 'accepted'],
            'preview_token' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
        ];
    }
}
