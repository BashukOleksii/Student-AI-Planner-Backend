<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanningAttentionStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip(['attention_count', 'most_important_kind', 'counts',
            'has_informational_recommendations', 'check_state', 'check_pending', 'last_successful_check_at',
            'check_failed', 'unverified_suggestion_count', 'review_refresh_required']));
    }
}
