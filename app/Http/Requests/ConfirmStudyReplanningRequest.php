<?php

namespace App\Http\Requests;

class ConfirmStudyReplanningRequest extends ConfirmStudyPlanRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'accept_unplaced_cancellation' => ['sometimes', 'required', 'boolean:strict']];
    }
}
