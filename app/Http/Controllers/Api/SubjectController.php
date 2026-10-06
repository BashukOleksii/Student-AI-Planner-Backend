<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use App\Services\Academic\SubjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SubjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SubjectResource::collection($request->user()->subjects()->orderBy('name')->orderBy('id')->get());
    }

    public function store(SaveSubjectRequest $request, SubjectService $service): JsonResponse
    {
        return (new SubjectResource($service->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(Request $request, Subject $subject): SubjectResource
    {
        Gate::authorize('view', $subject);

        return new SubjectResource($subject);
    }

    public function update(SaveSubjectRequest $request, Subject $subject, SubjectService $service): SubjectResource
    {
        return new SubjectResource($service->update($request->user(), $subject, $request->validated()));
    }

    public function destroy(Request $request, Subject $subject, SubjectService $service): Response
    {
        Gate::authorize('delete', $subject);
        $service->delete($request->user(), $subject);

        return response()->noContent();
    }
}
