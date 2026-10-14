<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyReplanningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = $this->resource;
        $result['session_results'] = array_map(fn ($row) => [
            'study_session_id' => $row['study_session_id'], 'task_id' => $row['task_id'], 'subtask_id' => $row['subtask_id'],
            'classification' => $row['classification'], 'original' => $this->interval($row['original']),
            'replacement' => $row['replacement'] === null ? null : $this->interval($row['replacement']),
            'untransferred_seconds' => $row['untransferred_seconds'], 'reason_codes' => $row['reason_codes'],
            'replacement_reason_codes' => $row['replacement_reason_codes'],
        ], $result['session_results']);

        return $result;
    }

    private function interval(array $interval): array
    {
        return ['starts_at' => gmdate('Y-m-d\TH:i:s\Z', $interval['start']), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $interval['end']),
            'duration_seconds' => $interval['end'] - $interval['start']];
    }
}
