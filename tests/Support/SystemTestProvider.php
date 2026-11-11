<?php

namespace Tests\Support;

use App\Services\AI\LlmProvider;
use RuntimeException;

/** Deterministic model boundary only; actual Tools, Services and persistence execute. */
final class SystemTestProvider implements LlmProvider
{
    public function respond(string $instructions, array $history, array $definitions): array
    {
        file_put_contents(getenv('PLANNER_SYSTEM_RUN').'/tool-definitions.json', json_encode($definitions, JSON_THROW_ON_ERROR));
        $last = end($history);
        if (($last['role'] ?? '') === 'tool') {
            return ['text' => 'Controlled provider: registered Tool execution finished. Review any pending action before confirming.', 'calls' => [], 'state' => []];
        }
        $content = $last['content'] ?? '';
        if (! is_string($content) || ! str_starts_with($content, 'SYSTEM_TOOL ')) {
            throw new RuntimeException('System tests require an explicit scripted Tool selection.');
        }
        $request = json_decode(substr($content, 12), true, 64, JSON_THROW_ON_ERROR);

        return ['text' => null, 'calls' => [['id' => 'system_'.bin2hex(random_bytes(6)), 'name' => $request['name'], 'arguments' => $request['arguments'] ?? []]], 'state' => []];
    }
}
