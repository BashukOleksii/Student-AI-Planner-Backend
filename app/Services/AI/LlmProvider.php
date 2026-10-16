<?php

namespace App\Services\AI;

interface LlmProvider
{
    /**
     * History events: role, content, calls, call_id and opaque provider state.
     * Response: text (?string), calls (list of {id, name, arguments: array}), state (array).
     * State contains only adapter-approved continuation data, never raw HTTP responses.
     */
    public function respond(string $instructions, array $history, array $definitions): array;
}
