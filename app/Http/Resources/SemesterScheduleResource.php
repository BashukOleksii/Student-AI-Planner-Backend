<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemesterScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $period = $this->academicPeriod;

        return [
            'id' => $this->id,
            'academic_period' => [
                'id' => $period->id, 'name' => $period->name,
                'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
            ],
            'group_name' => $this->group_name,
            'education_institution' => $period->educationInstitution->only(['id', 'name']),
            'parity_anchor_on' => $this->parity_anchor_on->format('Y-m-d'),
            'first_week_parity' => $this->first_week_parity,
        ];
    }
}
