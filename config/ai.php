<?php

// Preserve invalid input as null so AgentLimits can fail closed; never coerce "30oops" to 30.
$integer = static function (string $name, int $default): ?int {
    $value = env($name, $default);
    if (is_int($value)) {
        return $value;
    }
    if (! is_string($value) || ! preg_match('/\A[1-9][0-9]*\z/', $value)) {
        return null;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT);

    return $parsed === false ? null : $parsed;
};

return [
    'provider' => env('AI_PROVIDER', 'openai'),
    'openai_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL'),
    'request_timeout' => $integer('AI_REQUEST_TIMEOUT', 30),
    'max_tool_rounds' => $integer('AI_MAX_TOOL_ROUNDS', 5),
    'max_tool_calls' => $integer('AI_MAX_TOOL_CALLS', 8),
    'max_history_messages' => $integer('AI_MAX_HISTORY_MESSAGES', 20),
    'max_context_bytes' => $integer('AI_MAX_CONTEXT_BYTES', 131072),
    'max_tool_result_bytes' => $integer('AI_MAX_TOOL_RESULT_BYTES', 65536),
    'max_output_tokens' => $integer('AI_MAX_OUTPUT_TOKENS', 2048),
    'max_turn_seconds' => $integer('AI_MAX_TURN_SECONDS', 180),
    'turn_lease_seconds' => $integer('AI_TURN_LEASE_SECONDS', 240),
    'requests_per_minute' => $integer('AI_REQUESTS_PER_MINUTE', 10),
];
