<?php

namespace App\Services\AI;

use App\Exceptions\AiAgentException;

class AgentLimits
{
    public function __construct(private array $settings) {}

    public function validate(): void
    {
        foreach (['max_tool_rounds', 'max_tool_calls', 'max_history_messages', 'max_context_bytes',
            'max_tool_result_bytes', 'max_output_tokens', 'request_timeout', 'max_turn_seconds',
            'turn_lease_seconds', 'requests_per_minute'] as $name) {
            $this->get($name);
        }
        if ($this->get('turn_lease_seconds') <= $this->get('max_turn_seconds')) {
            throw new AiAgentException('ai_unavailable');
        }
    }

    public function get(string $name): int
    {
        $maximum = match ($name) {
            'max_tool_rounds' => 20, 'max_tool_calls' => 32, 'max_history_messages' => 100,
            'max_context_bytes' => 1048576, 'max_tool_result_bytes' => 262144,
            'max_output_tokens' => 8192, 'request_timeout' => 120, 'max_turn_seconds' => 600,
            'turn_lease_seconds' => 900, 'requests_per_minute' => 60,
        };
        $value = $this->settings[$name] ?? null;
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new AiAgentException('ai_unavailable');
        }

        return $value;
    }

    public function json(mixed $value, ?int $maximum = null): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > ($maximum ?? $this->get('max_context_bytes'))) {
            throw new AiAgentException('execution_limit_reached');
        }

        return $json;
    }
}
