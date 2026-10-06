<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveStudyAvailabilityWindowRequest;
use App\Http\Resources\StudyAvailabilityWindowResource;
use App\Models\StudyAvailabilityWindow;
use App\Services\Planning\StudyAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class StudyAvailabilityWindowController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return StudyAvailabilityWindowResource::collection(
            $request->user()->studyAvailabilityWindows()->orderBy('day_of_week')->orderBy('starts_at')->orderBy('id')->get(),
        );
    }

    public function store(SaveStudyAvailabilityWindowRequest $request, StudyAvailabilityService $service): JsonResponse
    {
        return (new StudyAvailabilityWindowResource($service->create($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(SaveStudyAvailabilityWindowRequest $request, StudyAvailabilityWindow $studyAvailabilityWindow, StudyAvailabilityService $service): StudyAvailabilityWindowResource
    {
        return new StudyAvailabilityWindowResource($service->update($request->user(), $studyAvailabilityWindow, $request->validated()));
    }

    public function destroy(Request $request, StudyAvailabilityWindow $studyAvailabilityWindow, StudyAvailabilityService $service): Response
    {
        Gate::authorize('delete', $studyAvailabilityWindow);
        $service->delete($request->user(), $studyAvailabilityWindow);

        return response()->noContent();
    }
}
