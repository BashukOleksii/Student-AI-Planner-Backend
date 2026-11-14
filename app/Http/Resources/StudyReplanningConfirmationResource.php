<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class StudyReplanningConfirmationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['applied' => true,
            'original_sessions' => $this->resource['original_sessions']->map(fn ($session) => [
                ...Arr::only((new StudySessionResource($session))->resolve($request), ['id', 'status', 'starts_at', 'ends_at', 'duration_seconds']),
            ])->values()->all(),
            'replacement_sessions' => StudySessionResource::collection($this->resource['replacement_sessions']),
            'replacement_groups' => $this->resource['replacement_sessions']->groupBy('rescheduled_from_session_id')->map(fn ($rows, $id) => ['original_session_id' => (int) $id, 'successor_session_ids' => $rows->modelKeys()])->values()->all(),
            'user_summary' => ['title' => 'Зміни плану збережено', 'next_step' => 'Перегляньте оновлені сесії у своєму плані.'],
            'summary' => $this->resource['summary']];
    }
}
