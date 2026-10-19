<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

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
        $message = AiMessage::factory()->create(['tool_calls' => ['historical' => ['retained' => true]]])->refresh();
        $conversation = $message->conversation;
        $before = [$conversation->getAttributes(), $message->getAttributes()];
        $this->artisan('migrate:rollback', ['--step' => 2])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('ai_pending_actions'));
        $this->assertFalse(Schema::hasTable('ai_conversation_turns'));
        $this->assertSame($before, [$conversation->fresh()->getAttributes(), $message->fresh()->getAttributes()]);
        $this->artisan('migrate')->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('ai_conversation_turns'));
        $this->assertSame($before, [$conversation->fresh()->getAttributes(), $message->fresh()->getAttributes()]);
    }
}
