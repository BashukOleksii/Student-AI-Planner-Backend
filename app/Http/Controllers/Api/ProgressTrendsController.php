<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProgressTrendsRequest;
use App\Http\Resources\ProgressTrendsResource;
use App\Services\Progress\ProgressTrendsService;

class ProgressTrendsController extends Controller
{
    public function index(ProgressTrendsRequest $request, ProgressTrendsService $service): ProgressTrendsResource
    {
        return new ProgressTrendsResource($service->calculate(
            $request->user(), $request->validated('start_date'), $request->validated('end_date'),
        ));
    }
}
