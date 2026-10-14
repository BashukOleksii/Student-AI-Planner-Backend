<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyReplanningConfirmationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['applied' => true,
            'original_sessions' => $this->resource['original_sessions']->map(fn ($session) => [
                'id' => $session->id, 'status' => $session->status->value,
            ])->values()->all(),
            'replacement_sessions' => StudySessionResource::collection($this->resource['replacement_sessions']),
            'summary' => $this->resource['summary']];
    }
}
