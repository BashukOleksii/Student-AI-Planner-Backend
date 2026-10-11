<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subject = $this->subject;

        return [
            'id' => $this->id,
            'subject_id' => $subject !== null && $subject->user_id === $this->user_id
                && $subject->user_id === $request->user()?->id ? $subject->getKey() : null,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'estimated_minutes' => $this->estimated_minutes,
            'deadline_at' => $this->utcTimestamp('deadline_at'),
            'completed_at' => $this->utcTimestamp('completed_at'),
        ];
    }

    private function utcTimestamp(string $field): ?string
    {
        $value = $this->resource->getRawOriginal($field);

        // DATETIME stores UTC wall-clock values, independent of server/user timezone.
        return $value === null ? null : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC')
            ->format('Y-m-d\TH:i:s\Z');
    }
}
