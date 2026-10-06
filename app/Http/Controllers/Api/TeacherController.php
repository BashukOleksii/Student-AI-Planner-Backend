<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Models\Teacher;
use App\Services\Academic\TeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class TeacherController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TeacherResource::collection($request->user()->teachers()->with([
            'educationInstitution' => fn ($query) => $query->where('user_id', $request->user()->id)->select(['id', 'user_id']),
        ])->orderBy('name')->orderBy('id')->get());
    }

    public function store(SaveTeacherRequest $request, TeacherService $service): JsonResponse
    {
        return (new TeacherResource($service->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(Request $request, Teacher $teacher): TeacherResource
    {
        Gate::authorize('view', $teacher);

        return new TeacherResource($teacher);
    }

    public function update(SaveTeacherRequest $request, Teacher $teacher, TeacherService $service): TeacherResource
    {
        return new TeacherResource($service->update($request->user(), $teacher, $request->validated()));
    }

    public function destroy(Request $request, Teacher $teacher, TeacherService $service): Response
    {
        Gate::authorize('delete', $teacher);
        $service->delete($request->user(), $teacher);

        return response()->noContent();
    }
}
