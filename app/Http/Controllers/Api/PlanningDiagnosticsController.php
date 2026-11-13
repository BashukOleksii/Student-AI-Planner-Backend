<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlanningDiagnosticsRequest;
use App\Http\Resources\PlanningDiagnosticsResource;
use App\Services\Planning\PlanningDiagnosticsService;

class PlanningDiagnosticsController extends Controller
{
    public function index(PlanningDiagnosticsRequest $request, PlanningDiagnosticsService $service): PlanningDiagnosticsResource
    {
        $values = $request->validated();

        return new PlanningDiagnosticsResource($service->analyze($request->user(), $values['start_date'], $values['end_date'],
            isset($values['min_slot_minutes']) ? (int) $values['min_slot_minutes'] * 60 : null));
    }
}
