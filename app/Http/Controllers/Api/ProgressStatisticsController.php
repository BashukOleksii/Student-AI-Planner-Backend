<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProgressStatisticsRequest;
use App\Http\Resources\ProgressStatisticsResource;
use App\Services\Progress\ProgressStatisticsService;

class ProgressStatisticsController extends Controller
{
    public function index(ProgressStatisticsRequest $request, ProgressStatisticsService $service): ProgressStatisticsResource
    {
        return new ProgressStatisticsResource($service->calculate(
            $request->user(), $request->validated('start_date'), $request->validated('end_date'),
        ));
    }
}
