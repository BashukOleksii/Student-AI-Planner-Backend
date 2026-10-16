<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProgressComparisonRequest;
use App\Http\Resources\ProgressComparisonResource;
use App\Services\Progress\ProgressComparisonService;

class ProgressComparisonController extends Controller
{
    public function index(ProgressComparisonRequest $request, ProgressComparisonService $service): ProgressComparisonResource
    {
        return new ProgressComparisonResource($service->calculate(
            $request->user(), $request->validated('baseline_start_date'), $request->validated('baseline_end_date'),
            $request->validated('current_start_date'), $request->validated('current_end_date'),
        ));
    }
}
