<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewStudyPlanRequest extends StudySessionRangeRequest
{
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function rules(): array
    {
        return [...parent::rules(),
            'task_id' => ['bail', 'sometimes', 'nullable', Rule::when(($this->validationData()['task_id'] ?? null) !== null, ['required']), 'integer:strict', 'min:1'],
            'subtask_id' => ['bail', 'sometimes', 'nullable', Rule::when(($this->validationData()['subtask_id'] ?? null) !== null, ['required']), 'integer:strict', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $data = $validator->getData();
            if (($data['subtask_id'] ?? null) !== null && ($data['task_id'] ?? null) === null) {
                $validator->errors()->add('task_id', 'A subtask requires its explicit parent task identifier.');
            }
        }];
    }
}
