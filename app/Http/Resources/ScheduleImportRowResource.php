<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $raw = $this->raw_data;
        $normalized = $this->normalized_data ?? [];

        return [
            'row_number' => $this->row_number,
            'status' => $this->status->value,
            'operation' => $normalized['operation'] ?? null,
            'group' => $raw['group'] ?? null,
            'workbook_source' => $raw['workbook_source'] ?? null,
            'local_date' => $raw['local_date'] ?? null,
            'weekday' => $raw['weekday'] ?? null,
            'pair_label' => $raw['pair_label'] ?? null,
            'parity' => $raw['parity'] ?? null,
            'subject_name' => $raw['subject_name'] ?? null,
            'subject_id' => $normalized['subject_id'] ?? null,
            'teacher_name' => $raw['teacher_name'] ?? null,
            'teacher_id' => $normalized['teacher_id'] ?? null,
            'room' => $normalized['room'] ?? null,
            'type' => $normalized['type'] ?? null,
            'starts_at_local' => $raw['starts_at_local'] ?? null,
            'ends_at_local' => $raw['ends_at_local'] ?? null,
            'starts_at_utc' => $normalized['starts_at_utc'] ?? null,
            'ends_at_utc' => $normalized['ends_at_utc'] ?? null,
            'source' => $raw['source'] ?? null,
            'validation_errors' => $this->validation_errors ?? [],
            'matched_lesson_id' => $normalized['matched_lesson_id'] ?? null,
        ];
    }
}
