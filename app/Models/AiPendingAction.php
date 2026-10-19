<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'conversation_id', 'turn_id', 'call_id', 'type', 'arguments', 'preview', 'target_hash', 'status', 'expires_at', 'receipt', 'resolved_at'])]
class AiPendingAction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    protected function casts(): array
    {
        return ['arguments' => 'array', 'preview' => 'array', 'receipt' => 'array',
            'expires_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
