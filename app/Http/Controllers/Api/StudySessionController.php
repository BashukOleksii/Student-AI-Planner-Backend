<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudySessionRangeRequest;
use App\Http\Resources\StudySessionResource;
use App\Services\Planning\StudySessionReadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StudySessionController extends Controller
{
    public function index(StudySessionRangeRequest $request, StudySessionReadService $service): AnonymousResourceCollection
    {
        $result = $service->range($request->user(), $request->validated('start_date'), $request->validated('end_date'));

        return StudySessionResource::collection($result['sessions'])->additional(['meta' => $result['meta']]);
    }

    public function show(Request $request, string $studySession, StudySessionReadService $service): StudySessionResource
    {
        $session = $service->find($request->user(), $studySession);
        Gate::authorize('view', $session);

        return new StudySessionResource($session);
    }
}
