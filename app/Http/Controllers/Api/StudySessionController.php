<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteStudySessionRequest;
use App\Http\Requests\RescheduleStudySessionRequest;
use App\Http\Requests\StudySessionActionRequest;
use App\Http\Requests\StudySessionRangeRequest;
use App\Http\Resources\StudySessionReschedulingResource;
use App\Http\Resources\StudySessionResource;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReadService;
use App\Services\Planning\StudySessionReschedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StudySessionController extends Controller
{
    public function complete(CompleteStudySessionRequest $request, string $studySession, StudySessionLifecycleService $service): StudySessionResource
    {
        return new StudySessionResource($service->complete($request->user(), $studySession, $request->validated('actual_minutes')));
    }

    public function miss(StudySessionActionRequest $request, string $studySession, StudySessionLifecycleService $service): StudySessionResource
    {
        return new StudySessionResource($service->miss($request->user(), $studySession));
    }

    public function cancel(StudySessionActionRequest $request, string $studySession, StudySessionLifecycleService $service): StudySessionResource
    {
        return new StudySessionResource($service->cancel($request->user(), $studySession));
    }

    public function reschedule(RescheduleStudySessionRequest $request, string $studySession, StudySessionReschedulingService $service): JsonResponse
    {
        $result = $service->reschedule($request->user(), $studySession, $request->validated('starts_at'), $request->validated('ends_at'));

        return (new StudySessionReschedulingResource($result))->response()->setStatusCode(201);
    }

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
