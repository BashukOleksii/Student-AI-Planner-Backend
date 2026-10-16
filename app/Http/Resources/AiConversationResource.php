<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'created_at' => $this->created_at?->utc()->toISOString(), 'updated_at' => $this->updated_at?->utc()->toISOString()];
    }
}
