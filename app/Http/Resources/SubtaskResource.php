<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubtaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'title' => $this->title,
            'description' => $this->description,
            'position' => $this->position,
            'status' => $this->status->value,
            'estimated_minutes' => $this->estimated_minutes,
            'deadline_at' => $this->utcTimestamp('deadline_at'),
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
