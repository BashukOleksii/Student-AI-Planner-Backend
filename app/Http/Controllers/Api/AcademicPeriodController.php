<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAcademicPeriodRequest;
use App\Http\Resources\AcademicPeriodResource;
use App\Models\AcademicPeriod;
use App\Services\Academic\AcademicPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AcademicPeriodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AcademicPeriodResource::collection($request->user()->academicPeriods()->orderBy('starts_on')->orderBy('id')->get());
    }

    public function store(SaveAcademicPeriodRequest $request, AcademicPeriodService $service): JsonResponse
    {
        return (new AcademicPeriodResource($service->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(Request $request, AcademicPeriod $academicPeriod): AcademicPeriodResource
    {
        Gate::authorize('view', $academicPeriod);

        return new AcademicPeriodResource($academicPeriod);
    }

    public function update(SaveAcademicPeriodRequest $request, AcademicPeriod $academicPeriod, AcademicPeriodService $service): AcademicPeriodResource
    {
        return new AcademicPeriodResource($service->update($request->user(), $academicPeriod, $request->validated()));
    }

    public function destroy(Request $request, AcademicPeriod $academicPeriod, AcademicPeriodService $service): Response
    {
        Gate::authorize('delete', $academicPeriod);
        $service->delete($request->user(), $academicPeriod);

        return response()->noContent();
    }
}
