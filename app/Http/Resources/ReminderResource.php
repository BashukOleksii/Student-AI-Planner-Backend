<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReminderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $time = fn (string $field) => $this->resource->getRawOriginal($field) === null ? null
            : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $this->resource->getRawOriginal($field), 'UTC')->format('Y-m-d\TH:i:s\Z');

        return ['id' => $this->id, 'task_id' => $this->task_id, 'subtask_id' => $this->subtask_id,
            'study_session_id' => $this->study_session_id, 'message' => $this->message,
            'trigger_at' => $time('trigger_at'), 'anchor' => $this->anchor?->value,
            'offset_minutes' => $this->offset_minutes, 'status' => $this->status->value, 'sent_at' => $time('sent_at')];
    }
}
