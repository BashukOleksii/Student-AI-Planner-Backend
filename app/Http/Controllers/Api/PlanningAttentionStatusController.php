<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanningAttentionStatusResource;
use App\Services\Planning\PlanningAttentionStatusService;
use Illuminate\Http\Request;

class PlanningAttentionStatusController extends Controller
{
    public function __invoke(Request $request, PlanningAttentionStatusService $service): PlanningAttentionStatusResource
    {
        return new PlanningAttentionStatusResource($service->status($request->user()));
    }
}
