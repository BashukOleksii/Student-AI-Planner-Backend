<?php

namespace App\Http\Resources;

use App\Services\Reminders\ReminderDeliveryRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reminder = $this->type === ReminderDeliveryRules::TYPE;
        $payload = $reminder ? app(ReminderDeliveryRules::class)->stored($this->data, $this->reminder_id) : [
            'reminder_id' => null, 'message' => 'You have a new notification.',
            'task_id' => null, 'subtask_id' => null, 'study_session_id' => null, 'trigger_at' => null];
        $time = fn (string $field) => $this->resource->getRawOriginal($field) === null ? null
            : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $this->resource->getRawOriginal($field), 'UTC')->format('Y-m-d\TH:i:s\Z');

        return ['id' => $this->id, 'type' => $reminder ? 'reminder' : 'notification', ...$payload,
            'created_at' => $time('created_at'), 'read_at' => $time('read_at')];
    }
}
