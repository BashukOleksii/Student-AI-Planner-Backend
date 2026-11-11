<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemesterScheduleImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $preview = $this->publicPreview($this->review);
        $rowFields = array_flip(['row_number', 'weekday', 'pair_number', 'pair_label', 'week_pattern', 'starts_at_local', 'ends_at_local',
            'subject_name', 'teacher_name', 'teacher_names', 'subject_id', 'subject', 'teacher_id', 'teacher', 'teacher_ids', 'teachers', 'room', 'source', 'import_key', 'operation', 'matched_rule_id', 'suppressed_slot_ids', 'errors']);
        $preview['rows'] = array_map(fn ($row) => array_intersect_key($row, $rowFields), $preview['rows']);

        return [
            'id' => $this->id, 'academic_period_id' => $this->academic_period_id,
            'original_filename' => $this->original_filename, 'status' => $this->status,
            'parity_anchor_on' => $this->parity_anchor_on->format('Y-m-d'), 'first_week_parity' => $this->first_week_parity,
            'preview_token' => $this->preview_token, 'can_commit' => $this->status === 'ready',
            'preview' => $preview, 'receipt' => $this->receipt,
            'committed_at' => $this->committed_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /** Prepared references have no database identity until commit. */
    private function publicPreview(array $value): array
    {
        foreach ($value as $key => &$item) {
            if (in_array($key, ['id', 'subject_id', 'teacher_id'], true) && is_int($item) && $item < 0) {
                $item = null;
            } elseif ($key === 'teacher_ids' && is_array($item)) {
                $item = array_map(fn ($id) => $id < 0 ? null : $id, $item);
            } elseif (is_array($item)) {
                $item = $this->publicPreview($item);
            }
        }
        unset($item);

        return $value;
    }
}
