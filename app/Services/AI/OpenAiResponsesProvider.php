<?php

namespace App\Services\AI;

use App\Exceptions\AiAgentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use stdClass;

class OpenAiResponsesProvider implements LlmProvider
{
    public function __construct(private AgentLimits $limits, private OpenAiToolSchema $schema) {}

    public function respond(string $instructions, array $history, array $definitions): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new AiAgentException('persistence_error');
        }
        $key = config('ai.openai_key');
        $model = config('ai.model');
        if (config('ai.provider') !== 'openai' || ! is_string($key) || trim($key) === ''
            || ! is_string($model) || ! preg_match('/\A[a-zA-Z0-9._:-]{1,100}\z/', $model)) {
            throw new AiAgentException('ai_unavailable');
        }
        $input = [];
        foreach ($history as $event) {
            if ($event['role'] === 'assistant' && ($event['state']['provider'] ?? null) === 'openai') {
                $state = $this->parse($event['state']['items'], $definitions)['state'];
                array_push($input, ...$state['items']);
            } elseif ($event['role'] === 'tool') {
                $input[] = ['type' => 'function_call_output', 'call_id' => $event['call_id'], 'output' => $event['content']];
            } elseif (in_array($event['role'], ['user', 'assistant'], true)) {
                $input[] = ['role' => $event['role'], 'content' => $event['content'] ?? ''];
            } else {
                throw new AiAgentException('invalid_provider_response');
            }
        }
        $body = ['model' => $model, 'instructions' => $instructions, 'input' => $input,
            'tools' => $this->schema->definitions($definitions), 'tool_choice' => 'auto', 'parallel_tool_calls' => true,
            'store' => false, 'include' => ['reasoning.encrypted_content'], 'truncation' => 'disabled',
            'max_output_tokens' => $this->limits->get('max_output_tokens')];
        $this->limits->json($body);
        try {
            $response = Http::withToken($key)->acceptJson()->asJson()->connectTimeout(min(10, $this->limits->get('request_timeout')))
                ->timeout($this->limits->get('request_timeout'))->withOptions(['allow_redirects' => false])
                ->post('https://api.openai.com/v1/responses', $body);
        } catch (ConnectionException) {
            throw new AiAgentException('provider_error');
        }
        if (! $response->successful()) {
            throw new AiAgentException(in_array($response->status(), [401, 403, 404], true) ? 'ai_unavailable' : 'provider_error');
        }
        if (strlen($response->body()) > 1048576) {
            throw new AiAgentException('execution_limit_reached');
        }
        try {
            $document = json_decode($response->body(), false, 64, JSON_THROW_ON_ERROR);
            if (! $document instanceof stdClass || ! is_array($document->output ?? null)) {
                throw new AiAgentException('invalid_provider_response');
            }
            $data = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['status'] ?? null) !== 'completed' || ($data['error'] ?? null) !== null
                || ($data['incomplete_details'] ?? null) !== null || ! is_array($data['output'] ?? null) || ! array_is_list($data['output'])
                || count($data['output']) > 64) {
                throw new AiAgentException('invalid_provider_response');
            }

            $parsed = $this->parse($data['output'], $definitions);
            $this->rejectCredentialEcho($parsed, $key);

            return $parsed;
        } catch (JsonException) {
            throw new AiAgentException('invalid_provider_response');
        }
    }

    private function parse(array $output, array $definitions): array
    {
        $items = $calls = $texts = $ids = [];
        foreach ($output as $item) {
            if (! is_array($item)) {
                throw new AiAgentException('invalid_provider_response');
            }
            switch ($item['type'] ?? null) {
                case 'function_call':
                    $id = $item['call_id'] ?? null;
                    $name = $item['name'] ?? null;
                    $arguments = $item['arguments'] ?? null;
                    if (! $this->identifier($id) || isset($ids[$id]) || ! is_string($name)
                        || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $name) || ! is_string($arguments) || strlen($arguments) > 16384
                        || ($item['status'] ?? 'completed') !== 'completed') {
                        throw new AiAgentException('invalid_provider_response');
                    }
                    $decoded = json_decode($arguments, false, 32, JSON_THROW_ON_ERROR);
                    if (! $decoded instanceof stdClass) {
                        throw new AiAgentException('invalid_provider_response');
                    }
                    $ids[$id] = true;
                    $calls[] = ['id' => $id, 'name' => $name, 'arguments' => $this->schema->arguments($name, (array) $decoded, $definitions)];
                    $items[] = ['type' => 'function_call', 'call_id' => $id, 'name' => $name, 'arguments' => $arguments,
                        ...($this->identifier($item['id'] ?? null) ? ['id' => $item['id']] : [])];
                    break;
                case 'message':
                    if (($item['role'] ?? null) !== 'assistant' || ($item['status'] ?? null) !== 'completed'
                        || ! is_array($item['content'] ?? null) || ! array_is_list($item['content'])) {
                        throw new AiAgentException('invalid_provider_response');
                    }
                    $content = [];
                    foreach ($item['content'] as $part) {
                        if (! is_array($part)) {
                            throw new AiAgentException('invalid_provider_response');
                        }
                        $field = match ($part['type'] ?? null) {
                            'output_text' => 'text', 'refusal' => 'refusal', default => null
                        };
                        if ($field === null || ! is_string($part[$field] ?? null) || ! mb_check_encoding($part[$field], 'UTF-8')) {
                            throw new AiAgentException('invalid_provider_response');
                        }
                        $texts[] = $part[$field];
                        $content[] = ['type' => $part['type'], $field => $part[$field], ...($field === 'text' ? ['annotations' => []] : [])];
                    }
                    $items[] = ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => $content,
                        ...($this->identifier($item['id'] ?? null) ? ['id' => $item['id']] : []),
                        ...(in_array($item['phase'] ?? null, ['commentary', 'final_answer'], true) ? ['phase' => $item['phase']] : [])];
                    break;
                case 'reasoning':
                    if (($item['status'] ?? 'completed') !== 'completed' || ! $this->identifier($item['id'] ?? null) || ! is_string($item['encrypted_content'] ?? null) || $item['encrypted_content'] === '') {
                        throw new AiAgentException('invalid_provider_response');
                    }
                    // Keep only encrypted continuation. Never store raw chain-of-thought or summaries.
                    $items[] = ['type' => 'reasoning', 'id' => $item['id'], 'summary' => [], 'encrypted_content' => $item['encrypted_content']];
                    break;
                default:
                    throw new AiAgentException('invalid_provider_response');
            }
        }
        $text = implode("\n", $texts);
        if (mb_strlen($text) > 16000 || ($calls === [] && preg_match('/\A[\s\p{Z}\p{C}]*\z/u', $text))) {
            throw new AiAgentException('invalid_provider_response');
        }
        $state = ['provider' => 'openai', 'items' => $items];
        $this->limits->json($state);

        return ['text' => $text === '' ? null : $text, 'calls' => $calls, 'state' => $state];
    }

    /** Reject verbatim transport credentials in arguments, identifiers and continuation as well as text. */
    private function rejectCredentialEcho(mixed $value, string $key): void
    {
        if (is_string($value) && str_contains($value, $key)) {
            throw new AiAgentException('invalid_provider_response');
        }
        if (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $field => $item) {
                if (is_string($field)) {
                    $this->rejectCredentialEcho($field, $key);
                }
                $this->rejectCredentialEcho($item, $key);
            }
        }
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $value) === 1;
    }
}
