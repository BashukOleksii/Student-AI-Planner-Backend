<?php

return [
    'provider' => env('AI_PROVIDER', 'openai'),
    'openai_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL'),
    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 30),
    'max_tool_rounds' => (int) env('AI_MAX_TOOL_ROUNDS', 5),
    'max_tool_calls' => (int) env('AI_MAX_TOOL_CALLS', 8),
    'max_history_messages' => (int) env('AI_MAX_HISTORY_MESSAGES', 20),
    'max_context_bytes' => (int) env('AI_MAX_CONTEXT_BYTES', 131072),
    'max_tool_result_bytes' => (int) env('AI_MAX_TOOL_RESULT_BYTES', 65536),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2048),
    'max_turn_seconds' => (int) env('AI_MAX_TURN_SECONDS', 180),
    'turn_lease_seconds' => (int) env('AI_TURN_LEASE_SECONDS', 240),
    'requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 10),
];
