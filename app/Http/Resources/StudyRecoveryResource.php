<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyRecoveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['recovery' => $this->resource['recovery'],
            'study_plan' => (new StudyPlanResource($this->resource['study_plan']))->resolve($request)];
    }
}
