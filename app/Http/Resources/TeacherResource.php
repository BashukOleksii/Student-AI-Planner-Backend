<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'education_institution_id' => $this->education_institution_id, 'name' => $this->name];
    }
}
