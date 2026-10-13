<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudySessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $this->resource->getRawOriginal('starts_at'), 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $this->resource->getRawOriginal('ends_at'), 'UTC');
        $completed = $this->resource->getRawOriginal('completed_at');

        return ['id' => $this->id, 'task_id' => $this->task_id, 'subtask_id' => $this->subtask_id,
            'starts_at' => $start->format('Y-m-d\TH:i:s\Z'), 'ends_at' => $end->format('Y-m-d\TH:i:s\Z'),
            'duration_seconds' => $end->getTimestamp() - $start->getTimestamp(), 'status' => $this->status->value,
            'completed_at' => $completed === null ? null : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $completed, 'UTC')->format('Y-m-d\TH:i:s\Z'),
            'actual_minutes' => $this->actual_minutes];
    }
}
