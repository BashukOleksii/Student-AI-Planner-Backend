<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreeSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'starts_at' => gmdate('Y-m-d\TH:i:s\Z', $this->resource['start']),
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $this->resource['end']),
            'duration_seconds' => $this->resource['end'] - $this->resource['start'],
        ];
    }
}
