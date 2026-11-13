<?php

namespace App\Http\Resources;

use App\Services\AI\CompletionReviewPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiPendingActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status === 'pending' && $this->elapsed ? 'expired' : $this->status;

        return ['id' => $this->id, 'conversation_id' => $this->conversation_id, 'call_id' => $this->call_id,
            'type' => $this->type, 'status' => $status, 'state' => $status === 'pending' ? 'confirmation_required' : $status,
            'confirmation_required' => $status === 'pending', 'preview' => CompletionReviewPrivacy::structured($this->preview),
            'expires_at' => $this->utcTimestamp('expires_at'), 'receipt' => CompletionReviewPrivacy::structured($this->receipt),
            'resolved_at' => $this->utcTimestamp('resolved_at'), 'created_at' => $this->utcTimestamp('created_at')];
    }

    private function utcTimestamp(string $field): ?string
    {
        $raw = $this->resource->getRawOriginal($field);

        return $raw === null ? null : CarbonImmutable::parse($raw, 'UTC')->toISOString();
    }
}
