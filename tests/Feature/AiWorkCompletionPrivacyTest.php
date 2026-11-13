<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\LlmProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiWorkCompletionPrivacyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13T10:00:00Z'));
        config(['ai.requests_per_minute' => 60]);
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public static function types(): array
    {
        return [['propose_task_complete'], ['propose_subtask_complete']];
    }

    private function fixture(string $type, bool $withSession = true): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $task = Task::factory()->for($owner)->create();
        $child = Subtask::factory()->for($task)->create();
        $isChild = $type === 'propose_subtask_complete';
        $arguments = ['task_id' => $task->id, ...($isChild ? ['subtask_id' => $child->id] : [])];
        $session = $withSession ? StudySession::factory()->for($task)->create([
            'subtask_id' => $isChild ? $child->id : null, 'starts_at' => '2026-11-14 10:00:00', 'ends_at' => '2026-11-14 11:00:00',
        ]) : null;
        $reminder = Reminder::factory()->for($owner)->create($isChild ? ['subtask_id' => $child->id] : ['task_id' => $task->id]);
        $sessionReminder = $session === null ? null : Reminder::factory()->for($owner)->create(['study_session_id' => $session->id]);
        $other = StudySession::factory()->for(Task::factory()->for($owner))->create([
            'starts_at' => '2026-11-14 12:00:00', 'ends_at' => '2026-11-14 13:00:00',
        ]);
        $this->actingAs($owner, 'web');
        $base = '/api/ai/conversations/'.$conversation->id;

        return compact('owner', 'conversation', 'task', 'child', 'session', 'reminder', 'sessionReminder', 'other', 'arguments', 'type', 'base');
    }

    private function snapshot(): array
    {
        return collect(['tasks', 'subtasks', 'study_sessions', 'reminders'])->mapWithKeys(fn ($table) => [
            $table => DB::table($table)->orderBy('id')->get()->all(),
        ])->all();
    }

    private function propose(array $f): array
    {
        $round = 0;
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->exactly(2))->method('respond')->willReturnCallback(function ($instructions, $history) use (&$round, $f): array {
            $this->assertStringNotContainsString('completion_token', json_encode($history));
            if ($round++ === 0) {
                return ['text' => null, 'calls' => [['id' => 'complete', 'name' => $f['type'], 'arguments' => $f['arguments']]], 'state' => []];
            }
            $result = json_decode($history[array_key_last($history)]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['ok']);
            $this->assertArrayHasKey('message', $result['data']['preview']['completion_review']);
            $this->assertSame($f['session'] === null ? 0 : 1, $result['data']['preview']['completion_review']['future_sessions_count']);

            return ['text' => 'Please review the consequences.', 'calls' => [], 'state' => []];
        });
        $this->app->instance(LlmProvider::class, $provider);
        $key = (string) Str::uuid();
        $response = $this->postJson($f['base'].'/messages', ['content' => 'Complete the reviewed work.'], ['Idempotency-Key' => $key])->assertOk();
        $this->assertStringNotContainsString('completion_token', $response->getContent());

        return [AiPendingAction::findOrFail($response->json('data.action.id')), $key];
    }

    private function assertPublicReads(array $f, AiPendingAction $action, string $secret): void
    {
        foreach ([$f['base'].'/actions', $f['base'].'/actions/'.$action->id, $f['base'].'/messages'] as $path) {
            $response = $this->getJson($path)->assertOk();
            $this->assertStringNotContainsString('completion_token', $response->getContent());
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->getJson($f['base'].'/actions/'.$action->id)->assertOk()
            ->assertJsonPath('data.preview.completion_review.future_sessions_count', $f['session'] === null ? 0 : 1);
    }

    private function assertRestoredHistoryIsPublic(array $f, string $secret): void
    {
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->once())->method('respond')->willReturnCallback(function ($instructions, $history) use ($secret): array {
            $this->assertStringNotContainsString('completion_token', json_encode($history));
            $this->assertStringNotContainsString($secret, json_encode($history));
            $tool = collect($history)->firstWhere('role', 'tool');
            $this->assertNotNull($tool);
            $result = json_decode($tool['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('message', $result['data']['preview']['completion_review']);

            return ['text' => 'The proposal still needs explicit confirmation.', 'calls' => [], 'state' => []];
        });
        $this->app->instance(LlmProvider::class, $provider);
        $this->postJson($f['base'].'/messages', ['content' => 'Explain the consequences again.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    }

    private function confirm(array $f, AiPendingAction $action)
    {
        return $this->json('POST', $f['base'].'/actions/'.$action->id.'/confirm', [], [], JSON_FORCE_OBJECT);
    }

    private function assertCompletesAndReplays(array $f, AiPendingAction $action): void
    {
        $response = $this->confirm($f, $action)->assertOk()->assertJsonPath('data.status', 'executed');
        $this->assertStringNotContainsString('completion_token', $response->getContent());
        $receipt = $response->json('data.receipt');
        $this->assertSame($f['type'] === 'propose_task_complete' ? 'completed' : 'pending', $f['task']->fresh()->status->value);
        $this->assertSame($f['type'] === 'propose_subtask_complete' ? 'completed' : 'pending', $f['child']->fresh()->status->value);
        if ($f['session'] !== null) {
            $this->assertSame('cancelled', $f['session']->fresh()->status->value);
            $this->assertNull($f['session']->fresh()->actual_minutes);
            $this->assertSame('cancelled', $f['sessionReminder']->fresh()->status->value);
        }
        $this->assertSame('cancelled', $f['reminder']->fresh()->status->value);
        $this->assertSame('planned', $f['other']->fresh()->status->value);
        $beforeReplay = $this->snapshot();
        $this->confirm($f, $action)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.receipt', $receipt);
        $this->assertEquals($beforeReplay, $this->snapshot());
    }

    #[DataProvider('types')]
    public function test_new_consent_is_encrypted_and_absent_from_public_storage_api_and_both_provider_rounds(string $type): void
    {
        $f = $this->fixture($type);
        $before = $this->snapshot();
        [$action] = $this->propose($f);
        $token = $action->protected_payload['completion_token'];
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertStringNotContainsString($token, $action->getRawOriginal('protected_payload'));
        $this->assertArrayNotHasKey('protected_payload', $action->toArray());
        $this->assertStringNotContainsString('completion_token', $action->getRawOriginal('preview'));
        $this->assertStringNotContainsString('completion_token', DB::table('ai_messages')->get()->toJson());
        $this->assertStringNotContainsString('completion_token', DB::table('ai_conversation_turns')->get()->toJson());
        $this->assertEquals($before, $this->snapshot());
        $this->assertPublicReads($f, $action, $token);
        $this->assertRestoredHistoryIsPublic($f, $token);
        $this->assertEquals($before, $this->snapshot());
        $this->assertCompletesAndReplays($f, $action);
    }

    private function makeLegacy(AiPendingAction $action, string $token): void
    {
        $preview = $action->preview;
        $preview['completion_review']['completion_token'] = $token;
        $action->update(['preview' => $preview, 'protected_payload' => null]);
    }

    #[DataProvider('types')]
    public function test_legacy_preview_tool_result_and_turn_replay_are_filtered_without_rewriting_and_can_confirm(string $type): void
    {
        $f = $this->fixture($type);
        [$action, $key] = $this->propose($f);
        $token = $action->protected_payload['completion_token'];
        $this->makeLegacy($action, $token);
        $tool = AiMessage::where('role', 'tool')->sole();
        $result = json_decode($tool->content, true, flags: JSON_THROW_ON_ERROR);
        $result['data']['preview'] = $action->fresh()->preview;
        $tool->update(['content' => json_encode($result, JSON_THROW_ON_ERROR)]);
        $turn = DB::table('ai_conversation_turns')->where('id', $action->turn_id)->sole();
        $storedResult = json_decode($turn->result, true, flags: JSON_THROW_ON_ERROR);
        $storedResult['action']['preview'] = $action->fresh()->preview;
        DB::table('ai_conversation_turns')->where('id', $turn->id)->update(['result' => json_encode($storedResult, JSON_THROW_ON_ERROR)]);
        $stored = [$action->fresh()->getAttributes(), $tool->fresh()->getAttributes(), DB::table('ai_conversation_turns')->where('id', $turn->id)->sole()->result];
        $before = $this->snapshot();
        $this->assertPublicReads($f, $action, $token);
        $replay = $this->postJson($f['base'].'/messages', ['content' => 'Complete the reviewed work.'], ['Idempotency-Key' => $key])->assertOk();
        $this->assertStringNotContainsString('completion_token', $replay->getContent());
        $this->assertStringNotContainsString($token, $replay->getContent());
        $this->assertRestoredHistoryIsPublic($f, $token);
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame($stored, [$action->fresh()->getAttributes(), $tool->fresh()->getAttributes(), DB::table('ai_conversation_turns')->where('id', $turn->id)->sole()->result]);
        $this->assertCompletesAndReplays($f, $action);
    }

    public static function invalidConsents(): array
    {
        $cases = [];
        foreach (self::types() as [$type]) {
            foreach (['absent', 'empty protected', 'wrong type', 'invalid token', 'broken ciphertext',
                'broken protected with valid legacy', 'missing protected key with valid legacy',
                'invalid protected token with valid legacy', 'invalid legacy', 'stale', 'stale legacy'] as $kind) {
                $cases[$type.' '.$kind] = [$type, $kind];
            }
        }

        return $cases;
    }

    public static function providerHistories(): array
    {
        $cases = [];
        foreach (self::types() as [$type]) {
            foreach (['openai', 'gemini'] as $provider) {
                foreach ([false, true] as $native) {
                    $cases[] = [$type, $provider, $native];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('providerHistories')]
    public function test_legacy_tool_result_is_private_in_native_and_portable_http_provider_payloads(string $type, string $provider, bool $native): void
    {
        $f = $this->fixture($type);
        [$action] = $this->propose($f);
        $token = $action->protected_payload['completion_token'];
        $tool = AiMessage::where('role', 'tool')->sole();
        $result = json_decode($tool->content, true, flags: JSON_THROW_ON_ERROR);
        $result['data']['preview']['completion_review']['completion_token'] = $token;
        $tool->update(['content' => json_encode($result, JSON_THROW_ON_ERROR)]);
        $stored = $tool->fresh()->getAttributes();
        $model = $provider === 'gemini' ? 'gemini-offline-fixture' : 'offline-test-model';
        if ($native) {
            $assistant = AiMessage::where('role', 'assistant')->orderBy('id')->firstOrFail();
            $metadata = $assistant->tool_calls;
            $metadata['state'] = $provider === 'openai'
                ? ['provider' => 'openai', 'items' => [['type' => 'function_call', 'call_id' => 'complete',
                    'name' => $type, 'arguments' => json_encode($f['arguments'], JSON_THROW_ON_ERROR)]]]
                : ['provider' => 'gemini', 'model' => $model, 'call_ids' => ['complete'],
                    'content' => json_encode(['role' => 'model', 'parts' => [['functionCall' => ['id' => 'complete', 'name' => $type,
                        'args' => (object) $f['arguments']], 'thoughtSignature' => base64_encode('opaque-call-signature')]]], JSON_THROW_ON_ERROR)];
            $assistant->update(['tool_calls' => $metadata]);
        }
        config(['ai.provider' => $provider, 'ai.openai_key' => 'private-openai-test-key', 'ai.model' => $model,
            'ai.gemini_key' => 'private-gemini-test-key', 'ai.gemini_model' => $model]);
        $this->app->forgetInstance(LlmProvider::class);
        Http::fake(function ($request) use ($provider, $token, $native) {
            $wire = json_encode($request->data(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('completion_token', $wire);
            $this->assertStringNotContainsString($token, $wire);
            $this->assertStringContainsString('future_sessions_count', $wire);
            if ($native) {
                $this->assertStringContainsString($provider === 'openai' ? 'function_call_output' : 'functionResponse', $wire);
                if ($provider === 'gemini') {
                    $this->assertStringContainsString(base64_encode('opaque-call-signature'), $wire);
                }
            } else {
                $this->assertStringContainsString('Historical tool result', $wire);
            }

            return Http::response($provider === 'gemini'
                ? ['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => 'Review retained.']]]]]]
                : ['id' => 'resp_public', 'status' => 'completed', 'output' => [['type' => 'message', 'id' => 'msg_public', 'role' => 'assistant',
                    'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Review retained.', 'annotations' => []]]]],
                    'error' => null, 'incomplete_details' => null]);
        });
        $before = $this->snapshot();
        $this->postJson($f['base'].'/messages', ['content' => 'Explain the review.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        Http::assertSentCount(1);
        $this->assertSame($stored, $tool->fresh()->getAttributes());
        $this->assertEquals($before, $this->snapshot());
    }

    #[DataProvider('types')]
    public function test_version_two_structured_event_is_filtered_before_api_and_context_text_serialization(string $type): void
    {
        $f = $this->fixture($type);
        [$action] = $this->propose($f);
        $token = $action->protected_payload['completion_token'];
        $review = [...$action->preview['completion_review'], 'completion_token' => $token];
        // Exercise the existing v2 envelope with a nested review in its opaque receipt data.
        $event = $f['conversation']->messages()->create(['role' => 'assistant', 'content' => 'Historical structured outcome.',
            'tool_calls' => ['version' => 2, 'action_event' => ['action_id' => $action->id, 'status' => 'executed',
                'receipt' => ['type' => $type, 'completion_review' => $review]]]]);
        $stored = $event->fresh()->getAttributes();
        $before = $this->snapshot();
        $response = $this->getJson($f['base'].'/messages')->assertOk();
        $this->assertStringNotContainsString('completion_token', $response->getContent());
        $publicEvent = collect($response->json('data'))->firstWhere('id', $event->id);
        $this->assertSame(1, $publicEvent['action_event']['receipt']['completion_review']['future_sessions_count']);
        $this->assertRestoredHistoryIsPublic($f, $token);
        $this->assertSame($stored, $event->fresh()->getAttributes());
        $this->assertEquals($before, $this->snapshot());
    }

    #[DataProvider('invalidConsents')]
    public function test_invalid_or_stale_consent_never_mutates_work_sessions_or_reminders(string $type, string $kind): void
    {
        $f = $this->fixture($type);
        [$action] = $this->propose($f);
        $token = $action->protected_payload['completion_token'];
        if (str_contains($kind, 'legacy')) {
            $this->makeLegacy($action, $kind === 'invalid legacy' ? 'invalid-consent' : $token);
        }
        match ($kind) {
            'absent' => $action->update(['protected_payload' => null]),
            'empty protected', 'missing protected key with valid legacy' => $action->update(['protected_payload' => []]),
            'wrong type' => $action->update(['protected_payload' => ['completion_token' => 123]]),
            'invalid token', 'invalid protected token with valid legacy' => $action->update(['protected_payload' => ['completion_token' => 'invalid-consent']]),
            'broken ciphertext', 'broken protected with valid legacy' => DB::table('ai_pending_actions')->where('id', $action->id)->update(['protected_payload' => 'not-encrypted']),
            'stale', 'stale legacy' => StudySession::factory()->for($f['task'])->create(['subtask_id' => $type === 'propose_subtask_complete' ? $f['child']->id : null,
                'starts_at' => '2026-11-14 14:00:00', 'ends_at' => '2026-11-14 15:00:00']),
            default => null,
        };
        $before = $this->snapshot();
        $response = $this->confirm($f, $action);
        $response->assertStatus(in_array($kind, ['invalid token', 'invalid protected token with valid legacy', 'invalid legacy', 'stale', 'stale legacy'], true) ? 409 : 422);
        $this->assertStringNotContainsString('completion_token', $response->getContent());
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame('pending', $action->fresh()->status);
        $this->assertNull($action->fresh()->receipt);
    }

    #[DataProvider('types')]
    public function test_missing_consent_cannot_complete_even_an_empty_review(string $type): void
    {
        $f = $this->fixture($type, false);
        [$action] = $this->propose($f);
        $action->update(['protected_payload' => null]);
        $before = $this->snapshot();
        $this->confirm($f, $action)->assertUnprocessable()->assertJsonPath('code', 'action_validation_failed');
        $this->assertEquals($before, $this->snapshot());
        $this->assertNull($action->fresh()->receipt);
    }
}
