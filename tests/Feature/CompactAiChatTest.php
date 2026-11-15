<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Lesson;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\AiConversationService;
use App\Services\AI\Tools\ToolExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

#[Group('ai')]
#[Group('mysql')]
class CompactAiChatTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => 'openai', 'ai.openai_key' => 'fake-test-key', 'ai.model' => 'gpt-5.4-mini']);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-10-16T22:30:00Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');

        return [$owner, $conversation];
    }

    private function url(AiConversation $conversation): string
    {
        return '/api/ai/conversations/'.$conversation->id.'/messages';
    }

    private function send(AiConversation $conversation, array $body = ['content' => 'Show my tasks.'], ?string $key = null)
    {
        return $this->postJson($this->url($conversation), $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_conversation_crud_reads_are_owned_bounded_ordered_and_do_not_call_provider(): void
    {
        [$owner, $conversation] = $this->fixture();
        $newer = AiConversation::factory()->for($owner)->create();
        AiConversation::factory()->create();
        $created = $this->post('/api/ai/conversations')->assertCreated();
        $this->assertSame(['id', 'created_at', 'updated_at'], array_keys($created->json('data')));
        $this->assertDatabaseHas('ai_conversations', ['id' => $created->json('data.id'), 'user_id' => $owner->id]);
        $this->getJson('/api/ai/conversations?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('meta.total', 3);
        $this->getJson('/api/ai/conversations/'.$conversation->id)->assertOk()->assertJsonPath('data.id', $conversation->id);
        $this->getJson('/api/ai/conversations?per_page=51')->assertUnprocessable();
        $this->getJson('/api/ai/conversations/'.$conversation->id.'?model=injected')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_greeting_turn_is_committed_once_and_duplicate_replay_is_durable_and_content_bound(): void
    {
        [$owner, $conversation] = $this->fixture();
        Http::fake(['*' => Http::response($this->aiResponse([$this->aiText('Hello.')]))]);
        $key = (string) Str::uuid();
        $first = $this->send($conversation, ['content' => 'Hello'], $key)->assertOk()->json('data');
        $this->assertSame($first, $this->send($conversation, ['content' => 'Hello'], $key)->assertOk()->json('data'));
        $this->assertSame($first, app(AiConversationService::class)->send($owner, (string) $conversation->id, 'Hello', $key));
        $this->send($conversation, ['content' => 'Changed'], $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('ai_messages', 2);
        $this->assertDatabaseCount('ai_conversation_turns', 1);
        $this->assertSame(['user', 'assistant'], $conversation->messages()->orderBy('id')->get()->map(fn ($m) => $m->role->value)->all());
        $this->assertSame([], $first['tools']);
        $this->assertSame($conversation->id, $first['conversation_id']);
        $this->assertDatabaseHas('ai_messages', ['id' => $first['assistant_message_id'], 'content' => 'Hello.']);
        Http::assertSentCount(1);
    }

    public static function tools(): array
    {
        return [0 => ['get_schedule_for_date', ['date' => '2026-10-20']]];
    }

    #[DataProvider('tools')]
    public function test_each_h1_tool_executes_with_real_owned_data_and_persists_correlated_history(string $name, array $arguments): void
    {
        [$owner, $conversation] = $this->fixture();
        $task = Task::factory()->for($owner)->create(['title' => 'Owned task']);
        Task::factory()->create(['title' => 'FOREIGN PRIVATE TASK']);
        Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-20 06:00:00', 'ends_at' => '2026-10-20 07:00:00']);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 2, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);
        $expected = app(ToolExecutor::class)->execute($owner, $name, $arguments);
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall($name, json_encode((object) $arguments))]))->push($this->aiResponse([$this->aiText()]));
        $data = $this->send($conversation)->assertOk()->json('data');
        $this->assertSame([['call_id' => 'call_1', 'name' => $name, 'ok' => true, 'error_code' => null]], $data['tools']);
        $messages = $conversation->messages()->orderBy('id')->get();
        $this->assertSame(['user', 'assistant', 'tool', 'assistant'], $messages->map(fn ($m) => $m->role->value)->all());
        $this->assertSame($expected, json_decode($messages[2]->content, true));
        $this->assertSame('call_1', $messages[2]->tool_call_id);
        $this->assertSame($name, $messages[1]->tool_calls['calls'][0]['name']);
        $requests = Http::recorded();
        $outputs = array_values(array_filter($requests[1][0]->data()['input'], fn ($item) => ($item['type'] ?? null) === 'function_call_output'));
        $this->assertSame('call_1', $outputs[0]['call_id']);
        $this->assertSame($expected, json_decode($outputs[0]['output'], true));
        $this->assertStringNotContainsString('FOREIGN PRIVATE TASK', json_encode($requests[1][0]->data()));
        $public = $this->getJson($this->url($conversation))->assertOk()->json('data');
        $this->assertNull($public[2]['content']);
        $this->assertTrue($public[2]['tool_result']['ok']);
        $this->assertStringNotContainsString('encrypted_content', json_encode($public));
        $this->assertStringNotContainsString('arguments', json_encode($public));
    }

    public function test_multiple_calls_multiple_rounds_and_second_message_replay_complete_structured_context(): void
    {
        [$owner, $conversation] = $this->fixture();
        Task::factory()->for($owner)->create();
        $reasoning = ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'encrypted-state'];
        Http::fakeSequence()->push($this->aiResponse([$reasoning, $this->aiCall('list_tasks', '{}', 'tasks'), $this->aiCall('get_schedule_for_date', '{"date":"2026-10-20"}', 'schedule')]))->push($this->aiResponse([$this->aiCall('get_progress_statistics', '{"start_date":"2026-10-20","end_date":"2026-10-20"}', 'stats')]))->push($this->aiResponse([$this->aiText('First complete answer.')]))->push($this->aiResponse([$this->aiText('Continuation answer.')]));
        $first = $this->send($conversation)->assertOk()->json('data');
        $second = $this->send($conversation, ['content' => 'And what does that mean?'])->assertOk()->json('data');
        $this->assertCount(3, $first['tools']);
        $this->assertGreaterThan($first['assistant_message_id'], $second['user_message_id']);
        $this->assertDatabaseCount('ai_messages', 9);
        $last = Http::recorded()[3][0]->data()['input'];
        $this->assertCount(3, array_filter($last, fn ($item) => ($item['type'] ?? null) === 'function_call_output'));
        $this->assertSame('encrypted-state', $last[1]['encrypted_content']);
        $this->assertSame('And what does that mean?', end($last)['content']);
        $history = $this->getJson($this->url($conversation))->assertOk();
        $this->assertStringNotContainsString('encrypted-state', $history->getContent());
        $this->assertSame([], $second['tools']);
    }

    public function test_history_pages_are_chronological_and_stable_under_appends_and_hide_system_metadata(): void
    {
        [$owner, $conversation] = $this->fixture();
        AiMessage::factory()->for($conversation, 'conversation')->create(['role' => 'system', 'content' => 'PRIVATE SYSTEM']);
        $messages = AiMessage::factory()->for($conversation, 'conversation')->count(4)->create();
        $first = $this->getJson($this->url($conversation).'?per_page=2')->assertOk();
        $this->assertSame($messages->take(2)->modelKeys(), array_column($first->json('data'), 'id'));
        AiMessage::factory()->for($conversation, 'conversation')->create();
        $next = $this->getJson($first->json('links.next'))->assertOk();
        $this->assertSame($messages->skip(2)->values()->modelKeys(), array_column($next->json('data'), 'id'));
        $this->assertSame(4, $next->json('meta.total'));
        $this->assertNull($next->json('links.next'));
        $this->assertStringNotContainsString('PRIVATE SYSTEM', $first->getContent());
    }

    public function test_bounded_history_drops_entire_leading_partial_turn_and_never_invents_orphan_call_context(): void
    {
        [$owner, $conversation] = $this->fixture();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall()]))->push($this->aiResponse([$this->aiText()]))->push($this->aiResponse([$this->aiText('New answer.')]));
        $this->send($conversation)->assertOk();
        config(['ai.max_history_messages' => 3]);
        $this->send($conversation, ['content' => 'Continue.'])->assertOk();
        $this->assertSame([['role' => 'user', 'content' => 'Continue.']], Http::recorded()[2][0]->data()['input']);
    }

    public static function invalidContents(): array
    {
        return [0 => [[]]];
    }

    #[DataProvider('invalidContents')]
    public function test_invalid_content_and_unknown_fields_fail_before_provider_or_turn_claim(array $body): void
    {
        [, $conversation] = $this->fixture();
        $this->send($conversation, $body)->assertUnprocessable();
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseCount('ai_conversation_turns', 0);
        Http::assertNothingSent();
    }

    public function test_json_object_utf8_idempotency_and_pagination_validation_are_exact(): void
    {
        [, $conversation] = $this->fixture();
        $this->postJson($this->url($conversation), ['content' => 'Hello'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        foreach (['[]', 'true', '{invalid', '"text"', "{\"content\":\"\xff\"}"] as $raw) {
            $this->call('POST', $this->url($conversation), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid()], $raw)->assertUnprocessable();
        }
        $this->post($this->url($conversation), ['content' => 'Hello'], ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])->assertUnprocessable();
        foreach (['page=0', 'page=01', 'page=1.0', 'page[]=1', 'per_page=51', 'through_id=-1', 'timezone=UTC'] as $query) {
            $this->getJson($this->url($conversation).'?'.$query)->assertUnprocessable();
        }
        $this->assertDatabaseCount('ai_messages', 0);
        Http::assertNothingSent();
    }
}
