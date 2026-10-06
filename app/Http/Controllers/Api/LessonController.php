<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveLessonRequest;
use App\Http\Resources\LessonResource;
use App\Models\Lesson;
use App\Services\Schedule\LessonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class LessonController extends Controller
{
    public function store(SaveLessonRequest $request, LessonService $service): JsonResponse
    {
        return (new LessonResource($service->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(Request $request, Lesson $lesson): LessonResource
    {
        Gate::authorize('view', $lesson);

        return new LessonResource($lesson);
    }

    public function update(SaveLessonRequest $request, Lesson $lesson, LessonService $service): LessonResource
    {
        return new LessonResource($service->update($request->user(), $lesson, $request->validated()));
    }

    public function destroy(Request $request, Lesson $lesson, LessonService $service): Response
    {
        Gate::authorize('delete', $lesson);
        $service->delete($request->user(), $lesson);

        return response()->noContent();
    }

    public function cancel(Request $request, Lesson $lesson, LessonService $service): LessonResource
    {
        Gate::authorize('update', $lesson);

        return new LessonResource($service->cancel($request->user(), $lesson));
    }

    public function replacement(SaveLessonRequest $request, Lesson $lesson, LessonService $service): JsonResponse
    {
        return (new LessonResource($service->createReplacement($request->user(), $lesson, $request->validated())))
            ->response()->setStatusCode(201);
    }
}
