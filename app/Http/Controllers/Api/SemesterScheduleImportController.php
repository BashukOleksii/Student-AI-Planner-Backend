<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CommitSemesterScheduleImportRequest;
use App\Http\Requests\ReviewSemesterScheduleImportRequest;
use App\Http\Requests\StoreSemesterScheduleImportRequest;
use App\Http\Resources\SemesterScheduleImportResource;
use App\Models\SemesterScheduleImport;
use App\Services\Imports\SemesterScheduleImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SemesterScheduleImportController extends Controller
{
    public function store(StoreSemesterScheduleImportRequest $request, SemesterScheduleImportService $service): JsonResponse
    {
        return (new SemesterScheduleImportResource($service->create($request->user(), $request->validated(), $request->file('schedule_file'))))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, SemesterScheduleImport $semesterScheduleImport): SemesterScheduleImportResource
    {
        Gate::authorize('view', $semesterScheduleImport);

        return new SemesterScheduleImportResource($request->user()->semesterScheduleImports()->findOrFail($semesterScheduleImport->id));
    }

    public function revalidate(ReviewSemesterScheduleImportRequest $request, SemesterScheduleImport $semesterScheduleImport, SemesterScheduleImportService $service): SemesterScheduleImportResource
    {
        return new SemesterScheduleImportResource($service->revalidate($request->user(), $semesterScheduleImport));
    }

    public function commit(CommitSemesterScheduleImportRequest $request, SemesterScheduleImport $semesterScheduleImport, SemesterScheduleImportService $service): JsonResponse
    {
        $result = $service->commit($request->user(), $semesterScheduleImport, $request->validated('preview_token'));
        $resource = new SemesterScheduleImportResource($result['import']);
        if ($result['changed']) {
            $resource->additional(['message' => 'The import preview changed. Review it and confirm again.']);
        }

        return $resource->response()->setStatusCode($result['changed'] ? 409 : 200);
    }
}
