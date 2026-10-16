<?php

namespace Tests\Feature;

use App\Exceptions\AiAgentException;
use App\Services\AI\LlmProvider;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

class OpenAiResponsesProviderTest extends TestCase
{
    use InteractsWithAiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.openai_key' => 'test-key-not-a-secret', 'ai.model' => 'gpt-5.4-mini']);
        Http::preventStrayRequests();
    }

    private function respond(array $history = []): array
    {
        return app(LlmProvider::class)->respond('Server instructions.', $history, app(ToolRegistry::class)->definitions());
    }

    public function test_request_uses_native_responses_function_contract_and_no_remote_conversation_or_retries(): void
    {
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('https://api.openai.com/v1/responses', $request->url());
            $this->assertSame(['Bearer test-key-not-a-secret'], $request->header('Authorization'));
            $body = $request->data();
            $this->assertSame('gpt-5.4-mini', $body['model']);
            $this->assertFalse($body['store']);
            $this->assertSame('disabled', $body['truncation']);
            $this->assertSame(['reasoning.encrypted_content'], $body['include']);
            $this->assertArrayNotHasKey('previous_response_id', $body);
            $this->assertArrayNotHasKey('conversation', $body);
            $this->assertCount(7, $body['tools']);
            foreach ($body['tools'] as $tool) {
                $this->assertTrue($tool['strict']);
                $this->assertSame('function', $tool['type']);
                $this->assertSame(array_keys($tool['parameters']['properties']), $tool['parameters']['required']);
            }

            return Http::response($this->aiResponse([$this->aiText()]));
        });
        $result = $this->respond([['role' => 'user', 'content' => 'Hello.']]);
        $this->assertSame([], $result['calls']);
        $this->assertSame('Here is the answer.', $result['text']);
        Http::assertSentCount(1);
    }

    public function test_strict_optional_nulls_are_removed_but_required_unknown_and_bad_types_reach_h1_unchanged(): void
    {
        Http::fakeSequence()->push($this->aiResponse([
            $this->aiCall('list_tasks', '{"status":null,"priority":null,"page":"1","unknown":null}', 'one'),
            $this->aiCall('get_schedule_for_date', '{"date":null}', 'two'),
        ]));
        $result = $this->respond();
        $this->assertSame(['page' => '1', 'unknown' => null], $result['calls'][0]['arguments']);
        $this->assertSame(['date' => null], $result['calls'][1]['arguments']);
        $this->assertSame(['one', 'two'], array_column($result['calls'], 'id'));
    }

    public function test_safe_reasoning_messages_and_function_items_are_replayed_with_correct_call_identifier(): void
    {
        $reasoning = ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Private reasoning summary']],
            'content' => ['hidden chain of thought'], 'encrypted_content' => 'opaque-encrypted-state'];
        Http::fakeSequence()->push($this->aiResponse([$reasoning, $this->aiCall()]))->push($this->aiResponse([$this->aiText()]));
        $first = $this->respond([['role' => 'user', 'content' => 'Tasks?']]);
        $this->assertSame([], $first['state']['items'][0]['summary']);
        $this->assertArrayNotHasKey('content', $first['state']['items'][0]);
        $this->respond([
            ['role' => 'user', 'content' => 'Tasks?'],
            ['role' => 'assistant', 'content' => null, 'calls' => $first['calls'], 'state' => $first['state']],
            ['role' => 'tool', 'call_id' => 'call_1', 'content' => '{"ok":true}'],
        ]);
        $requests = Http::recorded();
        $input = $requests[1][0]->data()['input'];
        $this->assertSame('opaque-encrypted-state', $input[1]['encrypted_content']);
        $this->assertSame('function_call', $input[2]['type']);
        $this->assertSame(['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"ok":true}'], $input[3]);
        $this->assertStringNotContainsString('hidden chain', json_encode($input));
    }

    public static function malformedResponses(): iterable
    {
        $call = ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'list_tasks', 'arguments' => '{}'];
        foreach (['{', '[]', 'null', 'true', '"text"'] as $json) {
            yield 'arguments '.$json => [['status' => 'completed', 'output' => [[...$call, 'arguments' => $json]]]];
        }
        foreach ([null, '', 'bad id', str_repeat('a', 129)] as $id) {
            yield [['status' => 'completed', 'output' => [[...$call, 'call_id' => $id]]]];
        }
        yield [['status' => 'completed', 'output' => [$call, $call]]];
        yield [['status' => 'completed', 'output' => [[...$call, 'name' => 'App\\Services::delete']]]];
        yield [['status' => 'incomplete', 'output' => [$call], 'incomplete_details' => ['reason' => 'max_output_tokens']]];
        yield [['status' => 'failed', 'error' => ['message' => 'private SQL']]];
        yield [['status' => 'completed', 'output' => []]];
        yield [['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'system', 'status' => 'completed', 'content' => []]]]];
        yield [['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'in_progress', 'content' => []]]]];
        yield [['status' => 'completed', 'output' => [['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []]]]];
        yield [['status' => 'completed', 'output' => [['type' => 'web_search_call']]]];
        yield [['status' => 'completed', 'output' => 'bad']];
        yield [['output' => []]];
        yield ['not json'];
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_or_incomplete_responses_are_rejected_safely(array|string $body): void
    {
        Http::fake(['*' => Http::response($body)]);
        try {
            $this->respond();
            $this->fail('Expected invalid provider response.');
        } catch (AiAgentException $exception) {
            $this->assertSame('invalid_provider_response', $exception->errorCode);
            $this->assertSame(502, $exception->status());
        }
        Http::assertSentCount(1);
    }

    public static function providerStatuses(): array
    {
        return [[400, 'provider_error'], [401, 'ai_unavailable'], [403, 'ai_unavailable'], [404, 'ai_unavailable'],
            [408, 'provider_error'], [429, 'provider_error'], [500, 'provider_error'], [503, 'provider_error'], [302, 'provider_error']];
    }

    #[DataProvider('providerStatuses')]
    public function test_http_errors_are_generic_and_never_retried(int $status, string $code): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'PRIVATE SQL credentials']], $status)]);
        try {
            $this->respond();
            $this->fail('Expected safe failure.');
        } catch (AiAgentException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_connection_failure_and_missing_configuration_do_not_leak_or_retry(): void
    {
        Http::fake(['*' => Http::failedConnection('PRIVATE connection credentials')]);
        try {
            $this->respond();
            $this->fail('Expected provider failure.');
        } catch (AiAgentException $exception) {
            $this->assertSame('provider_error', $exception->errorCode);
        }
        foreach ([['ai.openai_key' => null], ['ai.model' => null], ['ai.provider' => 'unknown']] as $values) {
            config(['ai.openai_key' => 'fake', 'ai.model' => 'gpt-5.4-mini', 'ai.provider' => 'openai', ...$values]);
            Http::fake();
            try {
                $this->respond();
                $this->fail('Expected unavailable configuration.');
            } catch (AiAgentException $exception) {
                $this->assertSame('ai_unavailable', $exception->errorCode);
            }
            Http::assertNothingSent();
        }
    }

    public function test_explicit_provider_refusal_is_a_valid_answer_but_whitespace_is_not(): void
    {
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('I cannot help with that.', 'refusal')]))
            ->push($this->aiResponse([$this->aiText(" \n\t")]));
        $result = $this->respond();
        $this->assertSame([], $result['calls']);
        $this->assertSame('I cannot help with that.', $result['text']);
        $this->expectException(AiAgentException::class);
        $this->respond();
    }

    public function test_provider_echo_of_transport_secret_is_rejected(): void
    {
        Http::fake(['*' => Http::response($this->aiResponse([$this->aiText('Key: test-key-not-a-secret')]))]);
        $this->expectException(AiAgentException::class);
        $this->respond();
    }

    public function test_network_transport_rejects_even_an_external_callers_open_transaction(): void
    {
        Http::fake();
        DB::beginTransaction();
        try {
            try {
                $this->respond();
                $this->fail('Provider I/O must reject an enclosing transaction.');
            } catch (AiAgentException $exception) {
                $this->assertSame('persistence_error', $exception->errorCode);
            }
        } finally {
            DB::rollBack();
        }
        Http::assertNothingSent();
    }
}
