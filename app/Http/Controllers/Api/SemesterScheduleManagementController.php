<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveScheduleDateExceptionRequest;
use App\Http\Requests\SaveSemesterScheduleRequest;
use App\Http\Requests\SaveWeeklyLessonRuleRequest;
use App\Http\Requests\SemesterScheduleManagementRequest;
use App\Http\Requests\StoreWorkingSaturdayRequest;
use App\Http\Resources\ScheduleDateExceptionResource;
use App\Http\Resources\SemesterScheduleLessonDefinitionResource;
use App\Http\Resources\SemesterScheduleManagementResource;
use App\Http\Resources\SemesterScheduleSuppressedSlotResource;
use App\Models\SemesterSchedule;
use App\Services\Schedule\SemesterScheduleManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SemesterScheduleManagementController extends Controller
{
    public function __construct(private SemesterScheduleManagementService $service) {}

    public function store(SaveSemesterScheduleRequest $request): JsonResponse
    {
        return (new SemesterScheduleManagementResource($this->service->create($request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function show(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule): SemesterScheduleManagementResource
    {
        return new SemesterScheduleManagementResource($this->service->read($request->user(), $semesterSchedule));
    }

    public function update(SaveSemesterScheduleRequest $request, SemesterSchedule $semesterSchedule): SemesterScheduleManagementResource
    {
        return new SemesterScheduleManagementResource($this->service->update($request->user(), $semesterSchedule, $request->validated()));
    }

    public function destroy(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule): JsonResponse
    {
        $this->service->delete($request->user(), $semesterSchedule, $request->validated());

        return response()->json(['message' => 'Порожній семестровий розклад видалено.']);
    }

    public function rules(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule): AnonymousResourceCollection
    {
        $state = $this->service->read($request->user(), $semesterSchedule);

        return SemesterScheduleLessonDefinitionResource::collection($state['rules'])->additional(['meta' => $state['meta']]);
    }

    public function rule(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule, int $weeklyRule): SemesterScheduleLessonDefinitionResource
    {
        $state = $this->service->read($request->user(), $semesterSchedule);

        return (new SemesterScheduleLessonDefinitionResource($state['rules']->find($weeklyRule) ?? abort(404)))->additional(['meta' => $state['meta']]);
    }

    public function storeRule(SaveWeeklyLessonRuleRequest $request, SemesterSchedule $semesterSchedule): JsonResponse
    {
        return (new SemesterScheduleManagementResource($this->service->createRule($request->user(), $semesterSchedule, $request->validated())))->response()->setStatusCode(201);
    }

    public function updateRule(SaveWeeklyLessonRuleRequest $request, SemesterSchedule $semesterSchedule, int $weeklyRule): SemesterScheduleManagementResource
    {
        return new SemesterScheduleManagementResource($this->service->updateRule($request->user(), $semesterSchedule, $weeklyRule, $request->validated()));
    }

    public function destroyRule(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule, int $weeklyRule): SemesterScheduleManagementResource
    {
        return new SemesterScheduleManagementResource($this->service->deleteRule($request->user(), $semesterSchedule, $weeklyRule, $request->validated()));
    }

    public function exceptions(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule): AnonymousResourceCollection
    {
        $state = $this->service->read($request->user(), $semesterSchedule);

        return ScheduleDateExceptionResource::collection($state['exceptions'])->additional(['meta' => $state['meta']]);
    }

    public function exception(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule, string $date): ScheduleDateExceptionResource
    {
        $state = $this->service->read($request->user(), $semesterSchedule);
        $exception = $state['exceptions']->first(fn ($row) => $row->occurs_on->format('Y-m-d') === $date) ?? abort(404);

        return (new ScheduleDateExceptionResource($exception))->additional(['meta' => $state['meta']]);
    }

    public function replaceDate(SaveScheduleDateExceptionRequest $request, SemesterSchedule $semesterSchedule, string $date): SemesterScheduleManagementResource
    {
        return new SemesterScheduleManagementResource($this->service->replaceDate($request->user(), $semesterSchedule, $date, $request->validated()));
    }

    public function destroyException(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule, string $date): SemesterScheduleManagementResource
    {
        return new SemesterScheduleManagementResource($this->service->deleteException($request->user(), $semesterSchedule, $date, $request->validated()));
    }

    public function storeSaturday(StoreWorkingSaturdayRequest $request, SemesterSchedule $semesterSchedule): JsonResponse
    {
        return (new SemesterScheduleManagementResource($this->service->workingSaturday($request->user(), $semesterSchedule, $request->validated())))->response()->setStatusCode(201);
    }

    public function suppressions(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule): AnonymousResourceCollection
    {
        $state = $this->service->read($request->user(), $semesterSchedule);

        return SemesterScheduleSuppressedSlotResource::collection($state['suppressions'])->additional(['meta' => $state['meta']]);
    }

    public function destroySuppression(SemesterScheduleManagementRequest $request, SemesterSchedule $semesterSchedule, int $suppressedSlot): SemesterScheduleManagementResource
    {
        return new SemesterScheduleManagementResource($this->service->releaseSuppression($request->user(), $semesterSchedule, $suppressedSlot, $request->validated()));
    }
}
