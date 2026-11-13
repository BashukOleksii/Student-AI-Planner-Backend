<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanningDiagnosticsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = $this->resource;
        $interval = fn ($row) => ['starts_at' => gmdate('Y-m-d\TH:i:s\Z', $row['start']),
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $row['end']), 'duration_seconds' => $row['end'] - $row['start']];
        $result['slots'] = array_map(fn ($slot) => [...$interval($slot), 'can_schedule' => $slot['can_schedule'],
            'candidate' => $slot['candidate'] === null ? null : $interval($slot['candidate']),
            'reason_codes' => $slot['reason_codes']], $result['slots']);

        return $result;
    }
}
