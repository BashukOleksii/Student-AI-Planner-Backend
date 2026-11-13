<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkloadAdviceRequest;
use App\Services\Progress\WorkloadAnalyticsService;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkloadAnalyticsController extends Controller
{
    public function index(WorkloadAdviceRequest $request, WorkloadAnalyticsService $service): JsonResource
    {
        return JsonResource::make($service->calculate($request->user(),
            $request->validated('start_date'), $request->validated('end_date')));
    }
}
