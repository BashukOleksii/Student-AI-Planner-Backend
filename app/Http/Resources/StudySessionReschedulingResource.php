<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudySessionReschedulingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['original' => (new StudySessionResource($this->resource['original']))->resolve($request),
            'replacement' => (new StudySessionResource($this->resource['replacement']))->resolve($request),
            'summary' => $this->resource['summary']];
    }
}
