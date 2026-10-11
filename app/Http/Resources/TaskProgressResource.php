<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'task_id' => $this->resource['task_id'],
            'total_subtasks' => $this->resource['total_subtasks'],
            'completed_subtasks' => $this->resource['completed_subtasks'],
            'progress_percent' => $this->resource['progress_percent'],
        ];
    }
}
