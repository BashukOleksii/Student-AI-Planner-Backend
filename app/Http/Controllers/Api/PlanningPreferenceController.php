<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePlanningPreferenceRequest;
use App\Http\Resources\PlanningPreferenceResource;
use App\Models\PlanningPreference;
use App\Services\Planning\PlanningPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanningPreferenceController extends Controller
{
    public function show(Request $request): PlanningPreferenceResource
    {
        return new PlanningPreferenceResource($request->user()->planningPreference()->first() ?? new PlanningPreference);
    }

    public function update(UpdatePlanningPreferenceRequest $request, PlanningPreferenceService $service): JsonResponse
    {
        return (new PlanningPreferenceResource($service->replace($request->user(), $request->validated())))
            ->response()->setStatusCode(200);
    }
}
