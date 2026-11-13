<?php

namespace App\Http\Resources;

use App\Services\Planning\PlanningWorkSelectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubtaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $parent = $this->task;
        $effective = $parent !== null && $parent->user_id === $request->user()?->id
            ? app(PlanningWorkSelectionService::class)->effectiveDeadline($parent, $this->resource) : null;

        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'title' => $this->title,
            'description' => $this->description,
            'position' => $this->position,
            'status' => $this->status->value,
            'estimated_minutes' => $this->estimated_minutes,
            'deadline_at' => $this->utcTimestamp('deadline_at'),
            'effective_deadline_at' => $effective === null ? null : gmdate('Y-m-d\TH:i:s\Z', $effective),
            'completed_at' => $this->utcTimestamp('completed_at'),
        ];
    }

    private function utcTimestamp(string $field): ?string
    {
        $value = $this->resource->getRawOriginal($field);

        return $value === null ? null : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC')
            ->format('Y-m-d\TH:i:s\Z');
    }
}
