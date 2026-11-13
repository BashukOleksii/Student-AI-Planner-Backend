<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

/** Optional public provenance for code-generated proposal summaries, never inferred from text. */
final class AiServerMessage
{
    public static function read(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        if (($value['kind'] ?? null) === 'proposal_not_created') {
            return ['kind' => 'proposal_not_created'];
        }
        if (($value['kind'] ?? null) === 'proposal_ready'
            && is_string($value['action_id'] ?? null) && Str::isUuid($value['action_id'])) {
            return ['kind' => 'proposal_ready', 'action_id' => $value['action_id']];
        }

        return null;
    }
}
