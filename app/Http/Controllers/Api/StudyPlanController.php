<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmStudyPlanRequest;
use App\Http\Requests\PreviewStudyPlanRequest;
use App\Http\Resources\StudyPlanResource;
use App\Services\Planning\StudyPlanPersistenceService;
use Illuminate\Http\JsonResponse;

class StudyPlanController extends Controller
{
    public function preview(PreviewStudyPlanRequest $request, StudyPlanPersistenceService $service): StudyPlanResource
    {
        $values = $request->validated();

        return new StudyPlanResource($service->preview($request->user(), $values['start_date'], $values['end_date'],
            $values['task_id'] ?? null, $values['subtask_id'] ?? null));
    }

    public function confirm(ConfirmStudyPlanRequest $request, StudyPlanPersistenceService $service): JsonResponse
    {
        return (new StudyPlanResource($service->confirm($request->user(), $request->validated('preview_token'),
            $request->validated('accept_partial', false))))->response()->setStatusCode(201);
    }
}
