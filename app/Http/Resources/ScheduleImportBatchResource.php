<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $committedAt = $this->resource->getRawOriginal('committed_at');

        return [
            'id' => $this->id,
            'academic_period_id' => $this->academic_period_id,
            'status' => $this->status->value,
            'original_filename' => $this->original_filename,
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'invalid_rows' => $this->invalid_rows,
            'duplicate_rows' => $this->duplicate_rows,
            'error_message' => $this->error_message,
            'committed_at' => $committedAt === null ? null
                : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $committedAt, 'UTC')->format('Y-m-d\TH:i:s\Z'),
            'rows' => ScheduleImportRowResource::collection($this->whenLoaded('rows')),
        ];
    }
}
