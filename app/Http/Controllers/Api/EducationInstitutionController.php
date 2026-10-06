<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEducationInstitutionRequest;
use App\Http\Resources\EducationInstitutionResource;
use App\Models\EducationInstitution;
use App\Services\Academic\EducationInstitutionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EducationInstitutionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return EducationInstitutionResource::collection($request->user()->educationInstitutions()->orderBy('name')->orderBy('id')->get());
    }

    public function store(SaveEducationInstitutionRequest $request): JsonResponse
    {
        try {
            $institution = $request->user()->educationInstitutions()->create($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['An education institution with this name already exists.']]);
        }

        return (new EducationInstitutionResource($institution))->response()->setStatusCode(201);
    }

    public function show(Request $request, EducationInstitution $educationInstitution): EducationInstitutionResource
    {
        Gate::authorize('view', $educationInstitution);

        return new EducationInstitutionResource($educationInstitution);
    }

    public function update(SaveEducationInstitutionRequest $request, EducationInstitution $educationInstitution): EducationInstitutionResource
    {
        try {
            $educationInstitution->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['An education institution with this name already exists.']]);
        }

        return new EducationInstitutionResource($educationInstitution);
    }

    public function destroy(Request $request, EducationInstitution $educationInstitution, EducationInstitutionService $service): Response
    {
        Gate::authorize('delete', $educationInstitution);
        $service->delete($request->user(), $educationInstitution);

        return response()->noContent();
    }
}
