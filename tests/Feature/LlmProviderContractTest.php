<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\Actions\SemesterActionArgumentValidator;
use App\Services\AI\LlmProvider;
use App\Services\AI\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\Support\AiCapabilities;
use Tests\TestCase;
use Tests\Unit\AgentOrchestratorTest;

/** Same public result and real orchestration assertions, with native provider fixtures. */
#[Group('mysql')]
#[Group('ai')]
class LlmProviderContractTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.openai_key' => 'offline-openai-key', 'ai.model' => 'gpt-5.4-mini',
            'ai.gemini_key' => 'offline-gemini-key', 'ai.gemini_model' => 'gemini-2.5-flash', 'ai.requests_per_minute' => 60]);
        $this->travelTo(CarbonImmutable::parse('2026-11-07T10:00:00Z'));
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public static function providers(): array
    {
        return [['gemini'], ['openai']];
    }

    #[DataProvider('providers')]
    public function test_normalized_contract_with_parallel_calls_mixed_text_and_continuation(string $provider): void
    {
        config(['ai.provider' => $provider]);
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('Checking.'), $this->aiCall(),
            $this->aiCall('get_schedule_for_date', '{"date":"2026-11-07"}', 'call_2')]))
            ->push($this->aiResponse([$this->aiText('Answer.')]));
        $history = [['role' => 'user', 'content' => 'Check my work.']];
        $adapter = app(LlmProvider::class);
        $definitions = app(ToolRegistry::class)->definitions();
        $first = $adapter->respond('Trusted instructions.', $history, $definitions);
        $this->assertSame(['text', 'calls', 'state'], array_keys($first));
        $this->assertSame('Checking.', $first['text']);
        $this->assertSame([['id' => 'call_1', 'name' => 'list_tasks', 'arguments' => []],
            ['id' => 'call_2', 'name' => 'get_schedule_for_date', 'arguments' => ['date' => '2026-11-07']]], $first['calls']);
        $this->assertSame($provider, $first['state']['provider']);
        $history[] = ['role' => 'assistant', 'content' => $first['text'], 'calls' => $first['calls'], 'state' => $first['state']];
        foreach ($first['calls'] as $call) {
            $history[] = ['role' => 'tool', 'call_id' => $call['id'], 'content' => '{"ok":true,"data":{}}'];
        }
        $last = $adapter->respond('Trusted instructions.', $history, $definitions);
        $this->assertSame('Answer.', $last['text']);
        $this->assertSame([], $last['calls']);
        Http::assertSentCount(2);
    }

    #[DataProvider('providers')]
    public function test_nested_semester_arguments_reach_shared_validation_and_keep_lists_and_nullable_fields(string $provider): void
    {
        config(['ai.provider' => $provider]);
        $arguments = ['semester_schedule_id' => 12, 'date' => '2026-11-09', 'mode' => 'copy_base',
            'changes' => [['weekly_lesson_rule_id' => 7, 'room' => 'New room', 'lesson_type' => null]]];
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall('propose_semester_date_replace', json_encode($arguments))]));
        $call = app(LlmProvider::class)->respond('Trusted instructions.', [], AiCapabilities::definitions())['calls'][0];
        $normalized = app(SemesterActionArgumentValidator::class)->validate($call['name'], $call['arguments']);
        $this->assertSame($arguments, $normalized);
        $this->assertTrue(array_is_list($normalized['changes']));
        $this->assertArrayHasKey('lesson_type', $normalized['changes'][0]);
        $this->assertNull($normalized['changes'][0]['lesson_type']);
        Http::assertSentCount(1);
    }

    public static function readTools(): iterable
    {
        foreach (['gemini', 'openai'] as $provider) {
            foreach ([...AgentOrchestratorTest::tools(), ['list_subtasks_for_task', []], ['list_reminders', []]] as [$name, $arguments]) {
                yield $provider.' '.$name => [$provider, $name, $arguments];
            }
        }
    }

    #[DataProvider('readTools')]
    public function test_core_and_management_reads_use_owned_services_and_keep_the_frontend_contract(string $provider, string $name, array $arguments): void
    {
        config(['ai.provider' => $provider]);
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $task = Task::factory()->for($owner)->create(['title' => 'Owned work']);
        Task::factory()->create(['title' => 'FOREIGN PRIVATE DATA']);
        if ($name === 'list_subtasks_for_task') {
            $arguments = ['task_id' => $task->id];
        }
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall($name, json_encode((object) $arguments))]))
            ->push($this->aiResponse([$this->aiText()]));
        $this->actingAs($owner, 'web');
        $url = '/api/ai/conversations/'.$conversation->id.'/messages';
        $key = (string) Str::uuid();
        $response = $this->postJson($url, ['content' => 'Read my data.'], ['Idempotency-Key' => $key])->assertOk();
        $this->assertSame([['call_id' => 'call_1', 'name' => $name, 'ok' => true, 'error_code' => null]], $response->json('data.tools'));
        $this->assertEqualsCanonicalizing(['conversation_id', 'user_message_id', 'assistant_message_id', 'content', 'tools'], array_keys($response->json('data')));
        $this->assertSame($response->json(), $this->postJson($url, ['content' => 'Read my data.'], ['Idempotency-Key' => $key])->assertOk()->json());
        $this->assertStringNotContainsString('FOREIGN PRIVATE DATA', json_encode(Http::recorded()[1][0]->data()));
        $public = $this->getJson($url)->assertOk()->getContent();
        foreach (['offline-gemini-key', 'state', 'arguments', 'thoughtSignature', 'FOREIGN PRIVATE DATA'] as $private) {
            $this->assertStringNotContainsString($private, $public);
        }
        Http::assertSentCount(2);
    }

    public function test_gemini_invalid_float_argument_remains_float_in_signed_continuation_after_mysql(): void
    {
        config(['ai.provider' => 'gemini']);
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');
        $part = ['functionCall' => ['name' => 'list_tasks', 'args' => ['page' => 1.0]],
            'thoughtSignature' => base64_encode('opaque typed call')];
        $first = json_encode(['modelVersion' => 'gemini-2.5-flash', 'candidates' => [['finishReason' => 'STOP',
            'content' => ['role' => 'model', 'parts' => [$part]]]]], JSON_PRESERVE_ZERO_FRACTION);
        Http::fakeSequence()->push($first)->push($this->aiResponse([$this->aiText('Invalid argument.')]))
            ->push($this->aiResponse([$this->aiText('Continued.')]));
        $url = '/api/ai/conversations/'.$conversation->id.'/messages';
        $this->postJson($url, ['content' => 'Read tasks.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('data.tools.0.ok', false)->assertJsonPath('data.tools.0.error_code', 'invalid_arguments');
        $this->postJson($url, ['content' => 'Continue.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        foreach ([1, 2] as $index) {
            $wire = json_decode(Http::recorded()[$index][0]->body(), true);
            $this->assertSame(1.0, $wire['contents'][1]['parts'][0]['functionCall']['args']['page']);
            $this->assertFalse($wire['contents'][2]['parts'][0]['functionResponse']['response']['ok']);
        }
        Http::assertSentCount(3);
    }

    public function test_gemini_signatures_and_generated_ids_survive_real_mysql_and_a_later_request(): void
    {
        config(['ai.provider' => 'gemini']);
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');
        $parts = [['text' => 'Checking.', 'thoughtSignature' => base64_encode('signed opaque text')],
            ['functionCall' => ['name' => 'list_tasks', 'args' => (object) []], 'thoughtSignature' => base64_encode('opaque function signature')]];
        Http::fakeSequence()->push(['modelVersion' => 'gemini-2.5-flash', 'candidates' => [['finishReason' => 'STOP',
            'content' => ['role' => 'model', 'parts' => $parts]]]])
            ->push($this->aiResponse([$this->aiText('First answer.')]))->push($this->aiResponse([$this->aiText('Later answer.')]));
        $url = '/api/ai/conversations/'.$conversation->id.'/messages';
        $first = $this->postJson($url, ['content' => 'Read tasks.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
        $id = $first['tools'][0]['call_id'];
        $this->assertStringStartsWith('gemini_', $id);
        $this->postJson($url, ['content' => 'Explain.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $wire = json_decode(json_encode(Http::recorded()[2][0]->data()), true);
        $this->assertSame(json_decode(json_encode($parts), true), $wire['contents'][1]['parts']);
        $this->assertArrayNotHasKey('id', $wire['contents'][1]['parts'][1]['functionCall']);
        $this->assertArrayNotHasKey('id', $wire['contents'][2]['parts'][0]['functionResponse']);
        $public = $this->getJson($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('thoughtSignature', $public);
        $this->assertStringNotContainsString(base64_encode('opaque function signature'), $public);
        $this->assertSame($id, $conversation->messages()->where('role', 'tool')->sole()->tool_call_id);
        Http::assertSentCount(3);
    }

    #[DataProvider('providers')]
    public function test_existing_conversation_continues_after_switch_without_rewriting_records(string $destination): void
    {
        $source = $destination === 'gemini' ? 'openai' : 'gemini';
        config(['ai.provider' => $source]);
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');
        $url = '/api/ai/conversations/'.$conversation->id.'/messages';
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall()]))->push($this->aiResponse([$this->aiText('Earlier answer.')]));
        $this->postJson($url, ['content' => 'Read tasks.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $before = AiMessage::orderBy('id')->get()->map(fn ($message) => $message->getRawOriginal())->all();
        config(['ai.provider' => $destination]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->aiResponse([$this->aiText('Continued safely.')]))]);
        $this->postJson($url, ['content' => 'Continue.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.content', 'Continued safely.');
        $this->assertSame($before, AiMessage::orderBy('id')->limit(count($before))->get()->map(fn ($message) => $message->getRawOriginal())->all());
        $wire = json_encode(Http::recorded()[0][0]->data());
        $this->assertStringContainsString('Historical tool result', $wire);
        $this->assertStringContainsString('Earlier answer.', $wire);
        $this->assertStringNotContainsString('function_call_output', $wire);
        $this->assertStringNotContainsString('functionResponse', $wire);
        Http::assertSentCount(1);
    }
}
