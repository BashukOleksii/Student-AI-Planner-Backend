<?php

namespace App\Services\AI;

use App\Exceptions\AiAgentException;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Throwable;

class GeminiGenerateContentProvider implements LlmProvider
{
    private const MAX_RESPONSE_BYTES = 1048576;

    public function __construct(private AgentLimits $limits, private GeminiToolSchema $schema) {}

    public function respond(string $instructions, array $history, array $definitions): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new AiAgentException('persistence_error');
        }
        $key = config('ai.gemini_key');
        $model = config('ai.gemini_model');
        if (config('ai.provider') !== 'gemini' || ! is_string($key) || ! preg_match('/\A[!-~]{1,256}\z/', $key)
            || ! is_string($model) || ! preg_match('/\Agemini-[a-zA-Z0-9._-]{1,90}\z/', $model)) {
            throw new AiAgentException('ai_unavailable');
        }
        // Google documents automatic rerouting/billing changes from this date. Require explicit reconfiguration.
        if ($model === 'gemini-2.5-flash' && CarbonImmutable::now('UTC')->greaterThanOrEqualTo('2026-12-01T00:00:00Z')) {
            throw new AiAgentException('ai_unavailable');
        }
        $this->limits->validate();
        try {
            $contents = $this->contents(ProviderHistory::forProvider($history, 'gemini', $model), $model);
        } catch (JsonException) {
            throw new AiAgentException('invalid_provider_response');
        }
        $body = ['systemInstruction' => ['parts' => [['text' => $instructions]]],
            'contents' => $contents,
            'tools' => [['functionDeclarations' => $this->schema->definitions($definitions)]],
            'toolConfig' => ['functionCallingConfig' => ['mode' => 'AUTO']],
            'generationConfig' => ['candidateCount' => 1, 'maxOutputTokens' => $this->limits->get('max_output_tokens'),
                'thinkingConfig' => ['includeThoughts' => false]]];
        $this->limits->json($body);
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > $this->limits->get('max_context_bytes')) {
            throw new AiAgentException('execution_limit_reached');
        }
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])->acceptJson()->withBody($json, 'application/json')
                ->connectTimeout(min(10, $this->limits->get('request_timeout')))->timeout($this->limits->get('request_timeout'))
                ->withOptions(['allow_redirects' => false, 'decode_content' => false,
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((float) $response->getHeaderLine('Content-Length') > self::MAX_RESPONSE_BYTES) {
                            throw new AiAgentException('execution_limit_reached');
                        }
                    },
                    'progress' => static function ($total, $downloaded): void {
                        if ($total > self::MAX_RESPONSE_BYTES || $downloaded > self::MAX_RESPONSE_BYTES) {
                            throw new AiAgentException('execution_limit_reached');
                        }
                    },
                ])->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent');
        } catch (ConnectionException|TransferException $exception) {
            // Guzzle can wrap an on_headers limit exception. Do not surface transport messages.
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof AiAgentException) {
                    throw new AiAgentException($cause->errorCode);
                }
            }
            throw new AiAgentException('provider_error');
        }
        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new AiAgentException('execution_limit_reached');
        }
        if (! $response->successful()) {
            $invalidKey = $response->status() === 400 && $this->invalidApiKey($response->body());
            $this->diagnose(['category' => $invalidKey ? 'credentials_rejected' : match ($response->status()) {
                401 => 'credentials_rejected', 403 => 'access_denied', 404 => 'model_inaccessible',
                429 => 'quota_or_rate_limit', default => 'http_error',
            }, 'status' => $response->status()]);
            throw new AiAgentException($invalidKey || in_array($response->status(), [401, 403, 404], true) ? 'ai_unavailable' : 'provider_error');
        }
        try {
            $document = json_decode($response->body(), false, 64, JSON_THROW_ON_ERROR);
            if (! $document instanceof stdClass || isset($document->error)
                || isset($document->promptFeedback->blockReason)
                || ! is_array($document->candidates ?? null) || count($document->candidates) !== 1) {
                throw new AiAgentException('invalid_provider_response');
            }
            if (isset($document->modelVersion) && $document->modelVersion !== $model) {
                $this->diagnose(['category' => 'model_version_mismatch']);
                throw new AiAgentException('ai_unavailable');
            }
            $candidate = $document->candidates[0];
            if (! $candidate instanceof stdClass || ($candidate->finishReason ?? null) !== 'STOP'
                || (isset($candidate->index) && $candidate->index !== 0)) {
                throw new AiAgentException('invalid_provider_response');
            }
            if (property_exists($candidate, 'safetyRatings') && ! is_array($candidate->safetyRatings)) {
                throw new AiAgentException('invalid_provider_response');
            }
            foreach ($candidate->safetyRatings ?? [] as $rating) {
                if (! $rating instanceof stdClass || (property_exists($rating, 'blocked') && $rating->blocked !== false)) {
                    throw new AiAgentException('invalid_provider_response');
                }
            }
            $parsed = $this->parseContent($candidate->content ?? null, $model);
            $this->rejectCredentialEcho($parsed, $key);

            return $parsed;
        } catch (JsonException) {
            throw new AiAgentException('invalid_provider_response');
        }
    }

    /** Keep each signed part exactly as received. Never retain thought summaries. */
    private function parseContent(mixed $content, string $model, ?array $internalIds = null): array
    {
        if (! $content instanceof stdClass || ($content->role ?? null) !== 'model'
            || ! is_array($content->parts ?? null) || $content->parts === [] || count($content->parts) > 64
            || array_diff(array_keys((array) $content), ['role', 'parts']) !== []) {
            throw new AiAgentException('invalid_provider_response');
        }
        $calls = $texts = $ids = $fingerprints = [];
        foreach ($content->parts as $part) {
            if (! $part instanceof stdClass || (property_exists($part, 'thought') && $part->thought !== false)
                || array_diff(array_keys((array) $part), ['text', 'functionCall', 'thoughtSignature', 'thought']) !== []
                || (property_exists($part, 'text') === property_exists($part, 'functionCall'))) {
                throw new AiAgentException('invalid_provider_response');
            }
            if (property_exists($part, 'thoughtSignature') && (! is_string($part->thoughtSignature)
                || strlen($part->thoughtSignature) > 65536 || $part->thoughtSignature === ''
                || base64_decode($part->thoughtSignature, true) === false)) {
                throw new AiAgentException('invalid_provider_response');
            }
            if (property_exists($part, 'text')) {
                if (! is_string($part->text) || ! mb_check_encoding($part->text, 'UTF-8')) {
                    throw new AiAgentException('invalid_provider_response');
                }
                $texts[] = $part->text;

                continue;
            }
            $call = $part->functionCall;
            if (! $call instanceof stdClass || ! $this->identifier($call->name ?? null, 64)
                || ! ($call->args ?? null) instanceof stdClass
                || array_diff(array_keys((array) $call), ['name', 'args', 'id']) !== []
                || (property_exists($call, 'id') && ! $this->identifier($call->id, 128))) {
                throw new AiAgentException('invalid_provider_response');
            }
            $this->limits->json($call->args, 16384);
            $fingerprint = $call->name.':'.json_encode($this->canonical($call->args), JSON_THROW_ON_ERROR);
            if (isset($fingerprints[$fingerprint])) {
                throw new AiAgentException('invalid_provider_response');
            }
            $fingerprints[$fingerprint] = true;
            $id = $internalIds[count($calls)] ?? ($call->id ?? 'gemini_'.bin2hex(random_bytes(16)));
            if (! $this->identifier($id, 128) || isset($ids[$id]) || (isset($call->id) && $id !== $call->id)) {
                throw new AiAgentException('invalid_provider_response');
            }
            $ids[$id] = true;
            // Keep nested JSON objects as objects; the current validators decide business validity.
            $calls[] = ['id' => $id, 'name' => $call->name, 'arguments' => (array) $call->args];
        }
        if (($internalIds !== null && count($internalIds) !== count($calls)) || count($calls) > $this->limits->get('max_tool_calls')) {
            throw new AiAgentException('invalid_provider_response');
        }
        $text = implode("\n", $texts);
        if (mb_strlen($text) > 16000 || ($calls === [] && preg_match('/\A[\s\p{Z}\p{C}]*\z/u', $text))) {
            throw new AiAgentException('invalid_provider_response');
        }
        $state = ['provider' => 'gemini', 'model' => $model, 'content' => json_encode($content, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'call_ids' => array_column($calls, 'id')];
        $this->limits->json($state);

        return ['text' => $text === '' ? null : $text, 'calls' => $calls, 'state' => $state];
    }

    private function contents(array $history, string $model): array
    {
        $contents = $pending = $seen = [];
        foreach ($history as $event) {
            if ($event['role'] === 'tool') {
                $id = $event['call_id'] ?? null;
                if (! is_string($id) || ! isset($pending[$id]) || ! $pending[$id] instanceof stdClass) {
                    throw new AiAgentException('invalid_provider_response');
                }
                $result = json_decode($event['content'], false, 64, JSON_THROW_ON_ERROR);
                if (! $result instanceof stdClass) {
                    throw new AiAgentException('invalid_provider_response');
                }
                $function = $pending[$id];
                $part = ['functionResponse' => ['name' => $function->name, 'response' => $result,
                    ...(isset($function->id) ? ['id' => $function->id] : [])]];
                // Parallel results share one user content, in the model's original call order.
                $pending[$id] = ['part' => $part];
                if (count(array_filter($pending, fn ($value) => is_array($value))) === count($pending)) {
                    $contents[] = ['role' => 'user', 'parts' => array_column($pending, 'part')];
                    $pending = [];
                }

                continue;
            }
            if ($pending !== []) {
                throw new AiAgentException('invalid_provider_response');
            }
            if ($event['role'] === 'assistant' && ($event['state']['provider'] ?? null) === 'gemini') {
                $state = $event['state'];
                // A JSON string preserves object/list boundaries through Eloquent's array cast.
                if (! is_string($state['content'] ?? null) || ! is_array($state['call_ids'] ?? null)
                    || ! array_is_list($state['call_ids'])) {
                    throw new AiAgentException('invalid_provider_response');
                }
                $content = json_decode($state['content'], false, 64, JSON_THROW_ON_ERROR);
                $parsed = $this->parseContent($content, $model, $state['call_ids'] ?? []);
                // Compare the Laravel-persisted projection; replay uses the original typed signed content.
                if ($this->persistedCalls($parsed['calls']) !== $this->persistedCalls($event['calls'] ?? [])) {
                    throw new AiAgentException('invalid_provider_response');
                }
                $index = 0;
                foreach ($content->parts as $part) {
                    if (isset($part->functionCall)) {
                        $id = $parsed['calls'][$index++]['id'];
                        if (isset($seen[$id])) {
                            throw new AiAgentException('invalid_provider_response');
                        }
                        $seen[$id] = true;
                        $pending[$id] = $part->functionCall;
                    }
                }
                $contents[] = $content;
            } elseif (in_array($event['role'], ['user', 'assistant'], true) && is_string($event['content'] ?? null)) {
                $contents[] = ['role' => $event['role'] === 'user' ? 'user' : 'model', 'parts' => [['text' => $event['content']]]];
            } else {
                throw new AiAgentException('invalid_provider_response');
            }
        }
        if ($pending !== []) {
            throw new AiAgentException('invalid_provider_response');
        }

        return $contents;
    }

    private function persistedCalls(array $calls): array
    {
        return $this->canonical(json_decode(json_encode($calls, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR));
    }

    /** MySQL JSON objects may reorder keys; comparison must still preserve scalar types. */
    private function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($this->canonical(...), $value);
        }

        return $value;
    }

    /** Google's documented GenerateContent 400 credential error; never classify by free-form message. */
    private function invalidApiKey(string $body): bool
    {
        try {
            $document = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if (! $document instanceof stdClass || ! is_array($document->error->details ?? null)) {
                return false;
            }
            foreach ($document->error->details as $detail) {
                if ($detail instanceof stdClass && ($detail->{'@type'} ?? null) === 'type.googleapis.com/google.rpc.ErrorInfo'
                    && ($detail->domain ?? null) === 'googleapis.com' && ($detail->reason ?? null) === 'API_KEY_INVALID') {
                    return true;
                }
            }
        } catch (JsonException) {
            // Malformed error documents remain a generic provider failure.
        }

        return false;
    }

    private function diagnose(array $context): void
    {
        try {
            Log::notice('AI provider request failed.', ['provider' => 'gemini', ...$context]);
        } catch (Throwable) {
            // Observability must preserve the fixed public failure contract.
        }
    }

    private function identifier(mixed $value, int $maximum): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9_-]{1,'.$maximum.'}\z/', $value) === 1;
    }

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
}
