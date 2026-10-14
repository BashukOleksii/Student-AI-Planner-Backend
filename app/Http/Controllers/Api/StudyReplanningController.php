<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmStudyReplanningRequest;
use App\Http\Requests\PreviewStudyPlanRequest;
use App\Http\Resources\StudyReplanningConfirmationResource;
use App\Http\Resources\StudyReplanningResource;
use App\Services\Planning\StudyReplanningPersistenceService;

class StudyReplanningController extends Controller
{
    public function preview(PreviewStudyPlanRequest $request, StudyReplanningPersistenceService $service): StudyReplanningResource
    {
        $values = $request->validated();

        return new StudyReplanningResource($service->preview($request->user(), $values['start_date'], $values['end_date'],
            $values['task_id'] ?? null, $values['subtask_id'] ?? null));
    }

    public function confirm(ConfirmStudyReplanningRequest $request, StudyReplanningPersistenceService $service): StudyReplanningConfirmationResource
    {
        $values = $request->validated();

        return new StudyReplanningConfirmationResource($service->confirm($request->user(), $values['preview_token'],
            $values['accept_partial'] ?? false, $values['accept_unplaced_cancellation'] ?? false));
    }
}
