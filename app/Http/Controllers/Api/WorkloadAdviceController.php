<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkloadAdviceRequest;
use App\Http\Resources\WorkloadAdviceResource;
use App\Services\Planning\WorkloadAdviceService;

class WorkloadAdviceController extends Controller
{
    public function index(WorkloadAdviceRequest $request, WorkloadAdviceService $service): WorkloadAdviceResource
    {
        return new WorkloadAdviceResource($service->analyze($request->user(),
            $request->validated('start_date'), $request->validated('end_date')));
    }
}
