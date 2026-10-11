<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleImportRequest;
use App\Http\Resources\ScheduleImportBatchResource;
use App\Models\ScheduleImportBatch;
use App\Services\Imports\ScheduleImportPreviewRevalidator;
use App\Services\Imports\ScheduleImportPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScheduleImportController extends Controller
{
    public function store(StoreScheduleImportRequest $request, ScheduleImportPreviewService $service): JsonResponse
    {
        $batch = $service->create($request->user(), $request->validated('academic_period_id'), $request->file('schedule_file'));

        return (new ScheduleImportBatchResource($batch))->response()->setStatusCode(201);
    }

    public function show(ScheduleImportBatch $scheduleImportBatch): ScheduleImportBatchResource
    {
        Gate::authorize('view', $scheduleImportBatch);
        $scheduleImportBatch->load(['rows' => fn ($query) => $query->orderBy('row_number')]);

        return new ScheduleImportBatchResource($scheduleImportBatch);
    }

    public function revalidate(Request $request, ScheduleImportBatch $scheduleImportBatch, ScheduleImportPreviewRevalidator $service): ScheduleImportBatchResource
    {
        Gate::authorize('revalidate', $scheduleImportBatch);

        return new ScheduleImportBatchResource($service->revalidate($request->user(), $scheduleImportBatch));
    }
}
