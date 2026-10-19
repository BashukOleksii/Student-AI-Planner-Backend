<?php

namespace Tests\Feature;

use App\Exceptions\AiAgentException;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\AI\LlmProvider;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\Concerns\InteractsWithSpaBrowser;
use Tests\TestCase;

class AiReadinessHardeningTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses, InteractsWithSpaBrowser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.openai_key' => 'h4-private-key', 'ai.model' => 'gpt-5.4-mini']);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public static function reasoningStatuses(): array
    {
        return [['incomplete'], ['in_progress'], ['unknown'], [false]];
    }

    #[DataProvider('reasoningStatuses')]
    public function test_unfinished_reasoning_items_are_rejected_even_when_the_response_claims_completion(mixed $status): void
    {
        Http::fakeSequence()->push($this->aiResponse([['type' => 'reasoning', 'id' => 'rs_1',
            'encrypted_content' => 'opaque-private', 'summary' => [], 'status' => $status], $this->aiText()]));
        $this->expectException(AiAgentException::class);
        $this->expectExceptionMessage('invalid response');
        app(LlmProvider::class)->respond('Server.', [], (new ToolRegistry)->definitions());
    }

    public static function badBudgets(): array
    {
        return [['max_tool_result_bytes', 0], ['max_tool_calls', -1], ['max_output_tokens', '2048'],
            ['max_history_messages', 101], ['request_timeout', 121], ['max_context_bytes', null],
            ['turn_lease_seconds', 180], ['requests_per_minute', 0]];
    }

    #[DataProvider('badBudgets')]
    public function test_invalid_configuration_fails_before_provider_work_even_for_a_greeting(string $field, mixed $value): void
    {
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');
        config(['ai.'.$field => $value]);
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('Hello.')]));
        $this->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Hello'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(503)->assertJsonPath('code', 'ai_unavailable');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseCount('ai_pending_actions', 0);
    }

    public function test_ai_csrf_error_has_a_safe_public_contract_even_with_debug_enabled(): void
    {
        $this->registerBrowser();
        config(['app.debug' => true]);
        $this->browser('POST', '/api/ai/conversations', headers: ['X-XSRF-TOKEN' => 'invalid'])
            ->assertStatus(419)->assertExactJson(['message' => 'CSRF token mismatch.']);
    }

    public function test_unexpected_ai_failure_never_reports_raw_exception_context_or_private_data(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'web');
        Log::spy();
        AiConversation::creating(fn () => throw new RuntimeException('PRIVATE-SQL-PROMPT-protected_token-credentials'));
        try {
            $this->json('POST', '/api/ai/conversations', [], [], JSON_FORCE_OBJECT)->assertStatus(500)
                ->assertExactJson(['message' => 'The AI conversation could not be processed.', 'code' => 'persistence_error']);
        } finally {
            AiConversation::flushEventListeners();
        }
        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('warning')->with('ai.failure', ['code' => 'persistence_error'])->once();
    }

    public static function boundedResponses(): array
    {
        return [[str_repeat('x', 1048577), 422, 'execution_limit_reached'],
            [['status' => 'completed', 'output' => array_fill(0, 65, ['type' => 'message'])], 502, 'invalid_provider_response']];
    }

    #[DataProvider('boundedResponses')]
    public function test_oversized_provider_output_never_commits_partial_history(mixed $body, int $status, string $code): void
    {
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');
        Http::fake(['*' => Http::response($body)]);
        $this->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Hello'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus($status)->assertJsonPath('code', $code);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseCount('ai_pending_actions', 0);
    }

    public static function credentialEchoes(): array
    {
        $call = ['type' => 'function_call', 'name' => 'propose_task_create', 'call_id' => 'call_secret', 'arguments' => '{"title":"h4-private-key"}'];

        return [[[$call]], [[[...$call, 'arguments' => '{"title":"h4-priv\\u0061te-key"}']]],
            [[['type' => 'reasoning', 'id' => 'rs_secret', 'encrypted_content' => 'h4-private-key', 'summary' => []],
                [...$call, 'arguments' => '{"title":"Safe title"}']]]];
    }

    #[DataProvider('credentialEchoes')]
    public function test_transport_secret_in_function_arguments_cannot_become_a_public_proposal_or_persisted_state(array $items): void
    {
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');
        Http::fakeSequence()->push($this->aiResponse($items))
            ->push($this->aiResponse([$this->aiText('Proposal prepared.')]));
        $response = $this->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Create a task'],
            ['Idempotency-Key' => (string) Str::uuid()]);
        $response->assertStatus(502)->assertJsonPath('code', 'invalid_provider_response');
        $this->assertStringNotContainsString('h4-private-key', $response->getContent());
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseCount('ai_pending_actions', 0);
        Http::assertSentCount(1);
    }
}
