<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcademicCalendarDateRequest;
use App\Http\Resources\AcademicCalendarLessonResource;
use App\Services\AI\Tools\EffectiveScheduleReadService;
use App\Services\Schedule\EffectiveScheduleCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AcademicCalendarController extends Controller
{
    public function __construct(private EffectiveScheduleReadService $calendar, private EffectiveScheduleCalculator $calculator) {}

    public function today(Request $request): AnonymousResourceCollection
    {
        $owner = $request->user()->fresh();
        $date = CarbonImmutable::now($owner->timezone)->toDateString();

        return $this->collection($this->calendar->range($owner, $date, $date, true));
    }

    public function date(AcademicCalendarDateRequest $request): AnonymousResourceCollection
    {
        $date = $request->validated('date');

        return $this->collection($this->calendar->range($request->user(), $date, $date, true));
    }

    public function week(AcademicCalendarDateRequest $request): AnonymousResourceCollection
    {
        [$start, $end] = $this->calculator->weekDates($request->validated('date'));

        return $this->collection($this->calendar->range($request->user(), $start, $end, true));
    }

    private function collection(array $result): AnonymousResourceCollection
    {
        return AcademicCalendarLessonResource::collection($result['lessons'])->additional(['meta' => $result['meta']]);
    }
}
