<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemesterScheduleSuppressedSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->only(['id', 'weekday', 'pair_number', 'week_pattern']);
    }
}
