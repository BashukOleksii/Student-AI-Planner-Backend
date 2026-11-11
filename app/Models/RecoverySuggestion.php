<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'user_id', 'active_owner_id', 'dedupe_key', 'source_hash', 'need_hash', 'refreshed_from_id', 'kind', 'status',
    'start_date', 'end_date', 'review', 'protected_token', 'receipt', 'expires_at', 'resolved_at'])]
#[Hidden(['protected_token', 'source_hash', 'need_hash', 'dedupe_key', 'active_owner_id'])]
class RecoverySuggestion extends Model
{
    public ?string $currentStatus = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['review' => 'array', 'receipt' => 'array', 'protected_token' => 'encrypted',
            'start_date' => 'immutable_date', 'end_date' => 'immutable_date',
            'expires_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
