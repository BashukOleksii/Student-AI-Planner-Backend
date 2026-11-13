<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\AI\AiConversationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\Concerns\UsesForwardOnlyMigrations as DatabaseMigrations;
use Tests\TestCase;

#[Group('mysql')]
#[Group('ai')]
#[Group('migration')]
class AiChatPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, DatabaseMigrations;

    public function test_turn_schema_unique_idempotency_and_conversation_cascade_are_enforced_by_mysql(): void
    {
        $this->assertTableDefinition('ai_conversation_turns', [
            'id' => ['bigint unsigned', false, null], 'conversation_id' => ['bigint unsigned', false, null],
            'request_id' => ['char(36)', false, null], 'input_hash' => ['char(64)', false, null],
            'lease_token' => ['char(36)', true, null], 'lease_expires_at' => ['datetime(6)', true, null],
            'result' => ['json', true, null], 'created_at' => ['timestamp', true, null], 'updated_at' => ['timestamp', true, null],
        ]);
        $this->assertForeignKeys('ai_conversation_turns', ['conversation_id' => ['ai_conversations', 'cascade']]);
        $this->assertIndex('ai_conversation_turns', 'ai_turns_conversation_request_unique', ['conversation_id', 'request_id'], true);
        $this->assertIndex('ai_conversation_turns', 'ai_turns_conversation_lease_index', ['conversation_id', 'lease_expires_at']);
        $this->assertIndex('ai_messages', 'ai_messages_conversation_order_index', ['conversation_id', 'id']);
        $first = AiConversation::factory()->create();
        $second = AiConversation::factory()->create();
        $values = ['conversation_id' => $first->id, 'request_id' => (string) Str::uuid(), 'input_hash' => hash('sha256', 'question')];
        DB::table('ai_conversation_turns')->insert($values);
        $this->assertMysqlRejects(fn () => DB::table('ai_conversation_turns')->insert($values), 1062, 'ai_turns_conversation_request_unique');
        DB::table('ai_conversation_turns')->insert([...$values, 'conversation_id' => $second->id]);
        $first->delete();
        $this->assertDatabaseCount('ai_conversation_turns', 1);
        $this->assertDatabaseHas('ai_conversation_turns', ['conversation_id' => $second->id]);
    }

    public function test_additive_migration_round_trip_preserves_existing_conversations_and_messages(): void
    {
        $this->migrateThrough('2026_10_16_000001_create_ai_conversation_turns_table', true);
        $message = AiMessage::factory()->create(['tool_calls' => ['historical' => ['retained' => true]]])->refresh();
        $conversation = $message->conversation;
        $before = [$conversation->getAttributes(), $message->getAttributes()];
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('ai_pending_actions'));
        $this->assertFalse(Schema::hasTable('ai_conversation_turns'));
        $this->assertSame($before, [$conversation->fresh()->getAttributes(), $message->fresh()->getAttributes()]);
        $this->migrateThrough('2026_10_16_000001_create_ai_conversation_turns_table');
        $this->assertTrue(Schema::hasTable('ai_conversation_turns'));
        $this->assertSame($before, [$conversation->fresh()->getAttributes(), $message->fresh()->getAttributes()]);
    }

    public function test_legacy_history_replay_and_receipts_are_not_rewritten_or_classified_by_their_text(): void
    {
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');
        $text = 'Task action proposed. Confirmation is required; no task has changed.';
        $user = $conversation->messages()->create(['role' => 'user', 'content' => 'Old request']);
        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => $text,
            'tool_calls' => ['version' => 1, 'calls' => [], 'state' => []]]);
        $uuid = (string) Str::uuid();
        $result = ['conversation_id' => $conversation->id, 'user_message_id' => $user->id,
            'assistant_message_id' => $assistant->id, 'content' => $text, 'tools' => [],
            'action' => ['id' => $uuid, 'state' => 'confirmation_required', 'call_id' => 'old', 'preview' => [], 'expires_at' => null]];
        $key = (string) Str::uuid();
        $turn = DB::table('ai_conversation_turns')->insertGetId(['conversation_id' => $conversation->id, 'request_id' => $key,
            'input_hash' => hash('sha256', 'Old request'), 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
        $receipt = ['action_id' => $uuid, 'type' => 'propose_task_create', 'task' => ['id' => 123, 'title' => 'Original Name', 'status' => 'pending']];
        $action = AiPendingAction::create(['id' => $uuid, 'conversation_id' => $conversation->id, 'turn_id' => $turn,
            'call_id' => 'old', 'type' => 'propose_task_create', 'arguments' => [], 'preview' => [], 'status' => 'executed',
            'receipt' => $receipt, 'expires_at' => now()->addDay(), 'resolved_at' => now()]);
        $before = [$assistant->fresh()->getAttributes(), $action->fresh()->getAttributes(), (array) DB::table('ai_conversation_turns')->find($turn)];
        $replay = app(AiConversationService::class)->send($owner, (string) $conversation->id, 'Old request', $key);
        $this->assertSame($result, $replay);
        $this->assertArrayNotHasKey('server_message', $replay);
        $history = collect($this->getJson('/api/ai/conversations/'.$conversation->id.'/messages')->assertOk()->json('data'));
        $this->assertSame($text, $history->firstWhere('id', $assistant->id)['content']);
        $this->assertArrayNotHasKey('server_message', $history->firstWhere('id', $assistant->id));
        $this->assertEquals($receipt, $this->getJson('/api/ai/conversations/'.$conversation->id.'/actions/'.$uuid)->assertOk()->json('data.receipt'));
        $this->assertSame($before, [$assistant->fresh()->getAttributes(), $action->fresh()->getAttributes(), (array) DB::table('ai_conversation_turns')->find($turn)]);
        Http::assertNothingSent();
    }
}
