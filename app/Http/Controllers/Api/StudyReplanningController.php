<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewStudyPlanRequest;
use App\Http\Resources\StudyReplanningResource;
use App\Services\Planning\StudyReplanningPreviewService;

class StudyReplanningController extends Controller
{
    public function preview(PreviewStudyPlanRequest $request, StudyReplanningPreviewService $service): StudyReplanningResource
    {
        $values = $request->validated();

        return new StudyReplanningResource($service->preview($request->user(), $values['start_date'], $values['end_date'],
            $values['task_id'] ?? null, $values['subtask_id'] ?? null));
    }
}
