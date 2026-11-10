<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemesterScheduleManagementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'schedule' => new SemesterScheduleResource($this->resource['schedule']),
            'weekly_rules' => SemesterScheduleLessonDefinitionResource::collection($this->resource['rules']),
            'date_exceptions' => ScheduleDateExceptionResource::collection($this->resource['exceptions']),
            'suppressed_slots' => SemesterScheduleSuppressedSlotResource::collection($this->resource['suppressions']),
        ];
    }

    public function with(Request $request): array
    {
        return ['meta' => $this->resource['meta']];
    }
}
