<?php

namespace Tests\Feature;

use App\Exceptions\AiAgentException;
use App\Services\AI\Actions\LessonActionRegistry;
use App\Services\AI\Actions\PlanningActionRegistry;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\AI\Actions\TaskActionRegistry;
use App\Services\AI\GeminiGenerateContentProvider;
use App\Services\AI\GeminiToolSchema;
use App\Services\AI\LlmProvider;
use App\Services\AI\OpenAiResponsesProvider;
use App\Services\AI\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class GeminiGenerateContentProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => 'gemini', 'ai.gemini_key' => 'gemini-private-test-key', 'ai.gemini_model' => 'gemini-2.5-flash']);
        $this->travelTo(CarbonImmutable::parse('2026-11-07T10:00:00Z'));
        Http::preventStrayRequests();
    }

    private function definitions(): array
    {
        return [...app(ToolRegistry::class)->definitions(), ...app(TaskActionRegistry::class)->definitions(),
            ...app(SubtaskReminderActionRegistry::class)->readDefinitions(), ...app(SubtaskReminderActionRegistry::class)->definitions(),
            ...app(PlanningActionRegistry::class)->definitions(), ...app(LessonActionRegistry::class)->definitions()];
    }

    private function respond(array $history = []): array
    {
        return app(LlmProvider::class)->respond('Trusted server instructions.', $history, $this->definitions());
    }

    private static function response(array $parts = [['text' => 'Answer.']]): array
    {
        return ['modelVersion' => 'gemini-2.5-flash', 'candidates' => [['index' => 0, 'finishReason' => 'STOP',
            'content' => ['role' => 'model', 'parts' => $parts]]]];
    }

    private static function functionPart(string $name = 'list_tasks', mixed $args = new stdClass, ?string $id = null): array
    {
        return ['functionCall' => ['name' => $name, 'args' => $args, ...($id === null ? [] : ['id' => $id])]];
    }

    private function failure(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a safe provider failure.');
        } catch (AiAgentException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertStringNotContainsString('private', $exception->getMessage());
        }
    }

    public function test_container_selects_only_the_explicit_provider_and_keeps_independent_configuration(): void
    {
        $this->assertInstanceOf(GeminiGenerateContentProvider::class, app(LlmProvider::class));
        config(['ai.provider' => 'openai']);
        $this->assertInstanceOf(OpenAiResponsesProvider::class, app(LlmProvider::class));
        config(['ai.provider' => 'unknown']);
        $this->failure('ai_unavailable', fn () => app(LlmProvider::class));
        Http::assertNothingSent();
    }

    public function test_request_has_official_authentication_model_budgets_and_all_27_unchanged_schemas(): void
    {
        config(['ai.openai_key' => 'must-not-send-openai-key', 'ai.model' => 'must-not-send-openai-model']);
        Http::fake(function ($request, $options) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', $request->url());
            $this->assertSame(['gemini-private-test-key'], $request->header('x-goog-api-key'));
            $this->assertFalse($request->hasHeader('Authorization'));
            $body = $request->data();
            $this->assertSame(['parts' => [['text' => 'Trusted server instructions.']]], $body['systemInstruction']);
            $this->assertSame([['role' => 'user', 'parts' => [['text' => 'Hello']]]], $body['contents']);
            $this->assertSame(['mode' => 'AUTO'], $body['toolConfig']['functionCallingConfig']);
            $this->assertSame(['candidateCount' => 1, 'maxOutputTokens' => 2048, 'thinkingConfig' => ['includeThoughts' => false]], $body['generationConfig']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(30, $options['timeout']);
            $this->assertSame(10, $options['connect_timeout']);
            foreach (['on_headers', 'progress'] as $callback) {
                $this->assertIsCallable($options[$callback]);
            }
            $declarations = $body['tools'][0]['functionDeclarations'];
            $this->assertCount(27, $declarations);
            $this->assertCount(18, array_filter($declarations, fn ($tool) => str_starts_with($tool['name'], 'propose_')));
            foreach ($declarations as $index => $declaration) {
                $definition = $this->definitions()[$index];
                $this->assertSame($definition['parameters'], $declaration['parametersJsonSchema']);
                $this->assertSame($definition['name'], $declaration['name']);
                $this->assertSame($definition['description'], $declaration['description']);
                $this->assertArrayNotHasKey('strict', $declaration);
                $this->assertArrayNotHasKey('parameters', $declaration);
            }
            $this->assertStringNotContainsString('must-not-send', json_encode($body));

            return Http::response(self::response());
        });
        $result = $this->respond([['role' => 'user', 'content' => 'Hello']]);
        $this->assertSame('Answer.', $result['text']);
        $this->assertSame([], $result['calls']);
        $this->assertSame('gemini', $result['state']['provider']);
        Http::assertSentCount(1);
    }

    public function test_parallel_calls_signatures_text_and_sequential_rounds_survive_json_persistence(): void
    {
        $parts = [['text' => 'Checking.', 'thoughtSignature' => base64_encode('signed text')],
            [...self::functionPart(), 'thoughtSignature' => base64_encode('opaque signature')],
            self::functionPart('get_schedule_for_date', (object) ['date' => '2026-11-07'], 'google_call_2'), ['text' => 'Following calls.']];
        Http::fakeSequence()->push(self::response($parts))
            ->push(self::response([self::functionPart('list_reminders')]))->push(self::response());
        $history = [['role' => 'user', 'content' => 'Check tasks and lessons.']];
        $first = $this->respond($history);
        $this->assertSame('Checking.\nFollowing calls.', str_replace("\n", '\n', $first['text']));
        $this->assertCount(2, $first['calls']);
        $this->assertNotSame($first['calls'][0]['id'], $first['calls'][1]['id']);
        $history[] = ['role' => 'assistant', 'content' => $first['text'], 'calls' => $first['calls'], 'state' => $first['state']];
        // Result arrival order is intentionally reversed; Gemini receives original model order.
        foreach (array_reverse($first['calls']) as $call) {
            $history[] = ['role' => 'tool', 'call_id' => $call['id'], 'content' => '{"ok":true,"data":{}}'];
        }
        $history = json_decode(json_encode($history), true);
        $second = $this->respond($history);
        $input = json_decode(Http::recorded()[1][0]->body(), true)['contents'];
        $this->assertEquals(json_decode(json_encode(['role' => 'model', 'parts' => $parts])), json_decode(Http::recorded()[1][0]->body())->contents[1]);
        $this->assertSame('list_tasks', $input[2]['parts'][0]['functionResponse']['name']);
        $this->assertArrayNotHasKey('id', $input[2]['parts'][0]['functionResponse']);
        $this->assertSame('google_call_2', $input[2]['parts'][1]['functionResponse']['id']);
        $history[] = ['role' => 'assistant', 'content' => $second['text'], 'calls' => $second['calls'], 'state' => $second['state']];
        $history[] = ['role' => 'tool', 'call_id' => $second['calls'][0]['id'], 'content' => '{"ok":false,"error":{"code":"invalid_arguments"}}'];
        $this->assertSame('Answer.', $this->respond($history)['text']);
        $input = Http::recorded()[2][0]->data()['contents'];
        $this->assertFalse($input[4]['parts'][0]['functionResponse']['response']['ok']);
        Http::assertSentCount(3);
    }

    public function test_omission_clearing_null_bad_types_and_unknown_names_are_not_repaired(): void
    {
        Http::fake(['*' => Http::response(self::response([
            self::functionPart('propose_lesson_update', (object) ['lesson_id' => 10, 'room' => null]),
            self::functionPart('propose_task_update', (object) ['task_id' => '10', 'description' => null, 'unknown' => true]),
            self::functionPart('unknown_tool', (object) ['date' => null]),
        ]))]);
        $calls = $this->respond()['calls'];
        $this->assertSame(['lesson_id' => 10, 'room' => null], $calls[0]['arguments']);
        $this->assertArrayNotHasKey('teacher_id', $calls[0]['arguments']);
        $this->assertSame(['task_id' => '10', 'description' => null, 'unknown' => true], $calls[1]['arguments']);
        $this->assertSame('unknown_tool', $calls[2]['name']);
        $this->assertSame(['date' => null], $calls[2]['arguments']);
    }

    public function test_foreign_openai_history_is_portable_data_without_opaque_state_or_orphan_functions(): void
    {
        Http::fake(['*' => Http::response(self::response())]);
        $history = [['role' => 'user', 'content' => 'Earlier request.'],
            ['role' => 'assistant', 'content' => null, 'calls' => [['id' => 'old_call', 'name' => 'list_tasks', 'arguments' => []]],
                'state' => ['provider' => 'openai', 'items' => [['encrypted_content' => 'do-not-send-opaque-state']]]],
            ['role' => 'tool', 'call_id' => 'old_call', 'content' => '{"ok":true,"data":{"tasks":[]}}'],
            ['role' => 'assistant', 'content' => 'Earlier answer.', 'calls' => [], 'state' => []],
            ['role' => 'user', 'content' => 'Continue.']];
        $before = $history;
        $this->respond($history);
        $body = json_encode(Http::recorded()[0][0]->data());
        $this->assertStringNotContainsString('do-not-send-opaque-state', $body);
        $this->assertStringNotContainsString('functionResponse', $body);
        $this->assertStringContainsString('Historical tool result', $body);
        $this->assertStringContainsString('Earlier answer.', $body);
        $this->assertSame($before, $history);
    }

    public static function invalidResponses(): iterable
    {
        foreach (['not json', '{', '[]', 'null', '{}'] as $body) {
            yield [$body];
        }
        yield [['promptFeedback' => ['blockReason' => 'SAFETY'], 'candidates' => []]];
        yield [['candidates' => []]];
        yield [['candidates' => [self::response()['candidates'][0], self::response()['candidates'][0]]]];
        foreach (['MAX_TOKENS', 'SAFETY', 'RECITATION', 'OTHER', 'MALFORMED_FUNCTION_CALL', '', null] as $reason) {
            $body = self::response();
            $body['candidates'][0]['finishReason'] = $reason;
            yield [$body];
        }
        foreach ([[], [['text' => '  ']], [['text' => str_repeat('x', 16001)]], [['inlineData' => []]],
            [['text' => 'private thoughts', 'thought' => true]], [['text' => null]],
            [['text' => 'ok', 'thought' => null]], array_fill(0, 65, ['text' => 'part']), [['text' => 'Hello', 'thoughtSignature' => 'invalid!']],
            [['text' => 'gemini-private-test-key']], [['text' => 'ok', 'functionCall' => ['name' => 'list_tasks', 'args' => new stdClass]]],
            [self::functionPart('App\\Service::delete')], [self::functionPart(args: [])], [self::functionPart(args: 'bad')], [self::functionPart(args: null)],
            [['functionCall' => ['name' => 'list_tasks']]], [self::functionPart(id: 'duplicate'), self::functionPart('list_reminders', id: 'duplicate')],
            [self::functionPart(), self::functionPart()], [self::functionPart(id: 'bad id')],
            [self::functionPart(args: (object) ['secret' => 'gemini-private-test-key'])],
        ] as $parts) {
            yield [self::response($parts)];
        }
        $body = self::response();
        $body['candidates'][0]['content']['role'] = 'user';
        yield [$body];
        $body = self::response();
        $body['candidates'][0]['safetyRatings'] = [['blocked' => true]];
        yield [$body];
        $body['candidates'][0]['safetyRatings'] = 'invalid';
        yield [$body];
        $body['candidates'][0]['safetyRatings'] = [null];
        yield [$body];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_blocked_truncated_or_secret_responses_never_succeed(mixed $body): void
    {
        Http::fake(['*' => Http::response($body)]);
        $this->failure('invalid_provider_response', fn () => $this->respond());
        Http::assertSentCount(1);
    }

    public static function httpErrors(): array
    {
        return [[400, 'provider_error', 'http_error'], [401, 'ai_unavailable', 'credentials_rejected'],
            [403, 'ai_unavailable', 'access_denied'], [404, 'ai_unavailable', 'model_inaccessible'],
            [429, 'provider_error', 'quota_or_rate_limit'], [500, 'provider_error', 'http_error'],
            [503, 'provider_error', 'http_error'], [302, 'provider_error', 'http_error']];
    }

    #[DataProvider('httpErrors')]
    public function test_http_failures_have_safe_diagnostics_and_no_retry_or_fallback(int $status, string $code, string $category): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['error' => ['message' => 'private gemini-private-test-key']], $status)]);
        $this->failure($code, fn () => $this->respond());
        Log::shouldHaveReceived('notice')->with('AI provider request failed.', ['provider' => 'gemini', 'category' => $category, 'status' => $status])->once();
        Http::assertSentCount(1);
    }

    public function test_documented_google_400_invalid_key_is_unavailable_without_disclosing_error_body(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['error' => ['message' => 'private gemini-private-test-key',
            'status' => 'INVALID_ARGUMENT', 'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com']]]], 400)]);
        $this->failure('ai_unavailable', fn () => $this->respond());
        Log::shouldHaveReceived('notice')->with('AI provider request failed.', ['provider' => 'gemini', 'category' => 'credentials_rejected', 'status' => 400])->once();
        Http::assertSentCount(1);
    }

    public function test_missing_credentials_invalid_models_and_documented_rerouting_date_fail_before_network(): void
    {
        Http::fake();
        foreach ([['ai.gemini_key' => null], ['ai.gemini_key' => ''], ['ai.gemini_key' => "bad\nkey"],
            ['ai.gemini_model' => null], ['ai.gemini_model' => 'models/gemini-2.5-flash'],
            ['ai.gemini_model' => 'https://attacker.test'], ['ai.gemini_model' => '../bad'], ['ai.request_timeout' => 0]] as $changes) {
            $original = config('ai');
            config($changes);
            $this->failure('ai_unavailable', fn () => $this->respond());
            config(['ai' => $original]);
        }
        $this->travelTo(CarbonImmutable::parse('2026-12-01T00:00:00Z'));
        $this->failure('ai_unavailable', fn () => $this->respond());
        Http::assertNothingSent();
    }

    public function test_network_errors_and_model_substitution_are_safe(): void
    {
        Http::fake(['*' => Http::failedConnection('private gemini-private-test-key')]);
        $this->failure('provider_error', fn () => $this->respond());
        Http::assertSentCount(1);
        Http::swap(new Factory);
        $body = self::response();
        $body['modelVersion'] = 'gemini-other-model';
        Http::fake(['*' => Http::response($body)]);
        $this->failure('ai_unavailable', fn () => $this->respond());
    }

    public function test_oversized_body_and_download_callbacks_enforce_limits(): void
    {
        Http::fake(function ($request, $options) {
            $this->failure('execution_limit_reached', fn () => $options['progress'](0, 1048577));
            $this->failure('execution_limit_reached', fn () => $options['on_headers'](new Response(200, ['Content-Length' => '1048577'])));

            return Http::response(str_repeat('x', 1048577));
        });
        $this->failure('execution_limit_reached', fn () => $this->respond());
    }

    public function test_context_argument_and_transaction_limits_fail_without_unsafe_io(): void
    {
        Http::fake();
        $this->failure('execution_limit_reached', fn () => $this->respond([['role' => 'user', 'content' => str_repeat('x', 131073)]]));
        DB::beginTransaction();
        try {
            $this->failure('persistence_error', fn () => $this->respond());
        } finally {
            DB::rollBack();
        }
        Http::assertNothingSent();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(self::response([self::functionPart(args: (object) ['title' => str_repeat('x', 16385)])]))]);
        $this->failure('execution_limit_reached', fn () => $this->respond());
    }

    public function test_configured_alternative_model_excludes_old_signed_state_without_modifying_history(): void
    {
        Http::fake(['*' => Http::response(self::response([[...self::functionPart(), 'thoughtSignature' => base64_encode('old model signature')]]))]);
        $first = $this->respond();
        $history = [['role' => 'assistant', 'content' => null, 'calls' => $first['calls'], 'state' => $first['state']],
            ['role' => 'tool', 'call_id' => $first['calls'][0]['id'], 'content' => '{"ok":true}'],
            ['role' => 'user', 'content' => 'Continue.']];
        $before = $history;
        config(['ai.gemini_model' => 'gemini-compatible-flash-fixture']);
        Http::swap(new Factory);
        $body = self::response();
        $body['modelVersion'] = 'gemini-compatible-flash-fixture';
        Http::fake(['*' => Http::response($body)]);
        $this->assertSame('Answer.', $this->respond($history)['text']);
        $request = Http::recorded()[0][0];
        $this->assertStringEndsWith('/gemini-compatible-flash-fixture:generateContent', $request->url());
        $this->assertStringNotContainsString(base64_encode('old model signature'), json_encode($request->data()));
        $this->assertStringContainsString('Historical tool result', json_encode($request->data()));
        $this->assertSame($before, $history);
    }

    public function test_orphan_duplicate_missing_and_tampered_continuation_results_fail_before_network(): void
    {
        Http::fake(['*' => Http::response(self::response([self::functionPart(id: 'original_call')]))]);
        $first = $this->respond();
        $assistant = ['role' => 'assistant', 'content' => null, 'calls' => $first['calls'], 'state' => $first['state']];
        $tool = ['role' => 'tool', 'call_id' => 'original_call', 'content' => '{"ok":true}'];
        $badArguments = $assistant;
        $badArguments['calls'][0]['arguments'] = ['unexpected' => 1];
        $badState = $assistant;
        $badState['state']['content'] = '{';
        $badIds = $assistant;
        $badIds['state']['call_ids'] = ['wrong_call'];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        foreach ([[$tool], [$assistant], [$assistant, $tool, $tool], [$assistant, [...$tool, 'call_id' => 'unknown']],
            [$assistant, [...$tool, 'content' => 'not json']], [$assistant, [...$tool, 'content' => '[]']],
            [$badArguments, $tool], [$badState, $tool], [$badIds, $tool],
            [$assistant, $tool, $assistant, $tool],
        ] as $history) {
            $this->failure('invalid_provider_response', fn () => $this->respond($history));
        }
        Http::assertNothingSent();
    }

    public function test_failed_diagnostics_cannot_change_public_error_classification(): void
    {
        Log::shouldReceive('notice')->andThrow(new \RuntimeException('private logging failure'));
        Http::fake(['*' => Http::response([], 429)]);
        $this->failure('provider_error', fn () => $this->respond());
        Http::assertSentCount(1);
    }

    public function test_nested_schema_is_preserved_and_unsupported_keywords_fail_closed(): void
    {
        $schema = ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'items' => [
            'type' => 'object', 'properties' => ['value' => ['type' => ['integer', 'null'], 'enum' => [1, 2, null]]],
            'required' => ['value'], 'additionalProperties' => false]]], 'required' => [], 'additionalProperties' => false];
        $definition = ['name' => 'nested', 'description' => 'Nested example', 'parameters' => $schema];
        $this->assertSame($schema, app(GeminiToolSchema::class)->definitions([$definition])[0]['parametersJsonSchema']);
        $definition['parameters']['oneOf'] = [];
        $this->failure('ai_unavailable', fn () => app(GeminiToolSchema::class)->definitions([$definition]));
    }
}
