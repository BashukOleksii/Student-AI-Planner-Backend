<?php

namespace App\Models;

use App\Enums\AiMessageRole;
use Database\Factories\AiMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversation_id', 'role', 'content', 'tool_calls', 'tool_call_id'])]
class AiMessage extends Model
{
    /** @use HasFactory<AiMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    protected function casts(): array
    {
        return [
            'role' => AiMessageRole::class,
            'tool_calls' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
