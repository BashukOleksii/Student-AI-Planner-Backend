<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecoverySuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->resource->currentStatus ?? $this->status;
        $actionable = $status === 'pending' && in_array($this->kind, ['create_plan', 'replan'], true);

        return ['id' => $this->id, 'kind' => $this->kind, 'status' => $status,
            'period' => $this->review['period'], 'actionable' => $actionable, 'confirmation_required' => $actionable,
            'requires_lifecycle_decision' => $this->review['requires_lifecycle_decision'],
            'required_confirmation_fields' => $this->review['required_confirmation_fields'],
            'reason_codes' => $this->review['reason_codes'], 'review' => $this->review,
            'receipt' => $this->receipt, 'refreshed_from_id' => $this->refreshed_from_id,
            'expires_at' => $this->expires_at->toISOString(), 'resolved_at' => $this->resolved_at?->toISOString(),
            'created_at' => $this->created_at->toISOString()];
    }
}
