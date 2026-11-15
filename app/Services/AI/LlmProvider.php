<?php

namespace App\Services\AI;

interface LlmProvider
{
    public function respond(string $instructions, array $history, array $definitions): array;
}
