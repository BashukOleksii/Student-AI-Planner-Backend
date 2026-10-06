<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $institution = $this->educationInstitution;
        $institutionId = $institution !== null && $institution->user_id === $this->user_id
            && $institution->user_id === $request->user()?->id ? $institution->getKey() : null;

        return ['id' => $this->id, 'education_institution_id' => $institutionId, 'name' => $this->name];
    }
}
