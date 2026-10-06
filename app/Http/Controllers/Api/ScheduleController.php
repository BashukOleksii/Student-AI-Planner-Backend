<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleDateRequest;
use App\Http\Resources\ScheduleLessonResource;
use App\Services\Schedule\ScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    public function today(Request $request, ScheduleService $service): AnonymousResourceCollection
    {
        return $this->collection($service->today($request->user()));
    }

    public function date(ScheduleDateRequest $request, ScheduleService $service): AnonymousResourceCollection
    {
        return $this->collection($service->date($request->user(), $request->validated('date')));
    }

    public function week(ScheduleDateRequest $request, ScheduleService $service): AnonymousResourceCollection
    {
        return $this->collection($service->week($request->user(), $request->validated('date')));
    }

    private function collection(array $schedule): AnonymousResourceCollection
    {
        return ScheduleLessonResource::collection($schedule['lessons'])->additional(['meta' => $schedule['meta']]);
    }
}
