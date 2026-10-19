<?php

namespace App\Http\Resources;

use App\Enums\AiMessageRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $calls = ($this->tool_calls['version'] ?? null) === 1 ? ($this->tool_calls['calls'] ?? []) : [];
        $calls = is_array($calls) ? array_values(array_filter($calls, fn ($call) => is_array($call)
            && is_string($call['id'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $call['id'])
            && is_string($call['name'] ?? null) && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $call['name']))) : [];
        $result = $this->role === AiMessageRole::Tool ? json_decode($this->content ?? '', true) : null;
        $content = $this->role === AiMessageRole::Tool ? null : $this->content;

        return ['id' => $this->id, 'role' => $this->role->value,
            'content' => $content === null ? null : mb_substr($content, 0, 16000),
            'content_truncated' => $content !== null && mb_strlen($content) > 16000,
            'tool_calls' => array_map(fn ($call) => ['call_id' => $call['id'], 'name' => $call['name']], array_slice($calls, 0, 32)),
            'tool_calls_truncated' => count($calls) > 32,
            'tool_result' => is_array($result) && is_bool($result['ok'] ?? null) && is_string($result['tool'] ?? null)
                && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $result['tool']) ? [
                    'call_id' => $this->tool_call_id, 'name' => $result['tool'], 'ok' => $result['ok'],
                    'error_code' => in_array($result['error']['code'] ?? null, ['unknown_tool', 'invalid_arguments', 'not_found', 'access_denied', 'execution_failed'], true)
                        ? $result['error']['code'] : null,
                ] : null,
            ...(($this->tool_calls['version'] ?? null) === 2 ? ['action_event' => $this->tool_calls['action_event']] : []),
            'created_at' => $this->created_at?->utc()->toISOString()];
    }
}
