<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SemesterScheduleDateRequest;
use App\Http\Resources\SemesterScheduleOccurrenceResource;
use App\Http\Resources\SemesterScheduleResource;
use App\Models\SemesterSchedule;
use App\Services\Schedule\EffectiveScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SemesterScheduleController extends Controller
{
    public function index(Request $request, EffectiveScheduleService $service): AnonymousResourceCollection
    {
        return SemesterScheduleResource::collection($service->schedules($request->user()));
    }

    public function show(Request $request, SemesterSchedule $semesterSchedule, EffectiveScheduleService $service): SemesterScheduleResource
    {
        Gate::authorize('view', $semesterSchedule);

        return new SemesterScheduleResource($service->detail($request->user(), $semesterSchedule));
    }

    public function date(SemesterScheduleDateRequest $request, SemesterSchedule $semesterSchedule, EffectiveScheduleService $service): AnonymousResourceCollection
    {
        return $this->collection($service->forDate($request->user(), $semesterSchedule, $request->validated('date')));
    }

    public function week(SemesterScheduleDateRequest $request, SemesterSchedule $semesterSchedule, EffectiveScheduleService $service): AnonymousResourceCollection
    {
        return $this->collection($service->forWeek($request->user(), $semesterSchedule, $request->validated('date')));
    }

    private function collection(array $schedule): AnonymousResourceCollection
    {
        return SemesterScheduleOccurrenceResource::collection($schedule['lessons'])->additional(['meta' => $schedule['meta']]);
    }
}
