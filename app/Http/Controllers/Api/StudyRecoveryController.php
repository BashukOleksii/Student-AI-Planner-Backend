<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewStudyRecoveryRequest;
use App\Http\Resources\StudyRecoveryResource;
use App\Services\Planning\StudyRecoveryPreviewService;

class StudyRecoveryController extends Controller
{
    public function preview(PreviewStudyRecoveryRequest $request, StudyRecoveryPreviewService $service): StudyRecoveryResource
    {
        $values = $request->validated();

        return new StudyRecoveryResource($service->preview($request->user(), $values['start_date'], $values['end_date'],
            $values['task_id'] ?? null, $values['subtask_id'] ?? null));
    }
}
