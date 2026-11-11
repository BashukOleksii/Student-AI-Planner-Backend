<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleDateExceptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'occurs_on' => $this->occurs_on->format('Y-m-d'),
            'kind' => $this->kind, 'copied_weekday' => $this->copied_weekday, 'source_week_parity' => $this->source_week_parity,
            'lessons' => SemesterScheduleLessonDefinitionResource::collection($this->lessons)];
    }
}
