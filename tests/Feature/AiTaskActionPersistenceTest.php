<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

class AiTaskActionPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, DatabaseMigrations;

    private function fixture(): array
    {
        $conversation = AiConversation::factory()->create();
        $turn = DB::table('ai_conversation_turns')->insertGetId(['conversation_id' => $conversation->id,
            'request_id' => (string) Str::uuid(), 'input_hash' => hash('sha256', 'question'), 'result' => '{}']);
        $values = ['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'turn_id' => $turn,
            'call_id' => 'call_1', 'type' => 'propose_task_create', 'arguments' => '{"title":"Preserved"}',
            'preview' => '{"confirmation_required":true}', 'expires_at' => '2026-10-23 12:00:00.123456'];

        return [$conversation, $values];
    }

    public function test_schema_keys_constraints_json_microseconds_and_conversation_cascade(): void
    {
        $this->assertTableDefinition('ai_pending_actions', [
            'id' => ['char(36)', false, null], 'conversation_id' => ['bigint unsigned', false, null],
            'turn_id' => ['bigint unsigned', false, null], 'call_id' => ['varchar(128)', false, null],
            'type' => ['varchar(32)', false, null], 'arguments' => ['json', false, null], 'preview' => ['json', false, null],
            'target_hash' => ['char(64)', true, null], 'status' => ['varchar(16)', false, 'pending'],
            'expires_at' => ['datetime(6)', false, null], 'receipt' => ['json', true, null],
            'resolved_at' => ['datetime(6)', true, null], 'created_at' => ['timestamp', true, null], 'updated_at' => ['timestamp', true, null],
        ], false);
        $this->assertForeignKeys('ai_pending_actions', ['conversation_id' => ['ai_conversations', 'cascade'], 'turn_id' => ['ai_conversation_turns', 'cascade']]);
        $this->assertIndex('ai_pending_actions', 'ai_pending_actions_turn_id_unique', ['turn_id'], true);
        $this->assertIndex('ai_pending_actions', 'ai_actions_conversation_created_index', ['conversation_id', 'created_at']);
        [$conversation, $values] = $this->fixture();
        DB::table('ai_pending_actions')->insert($values);
        $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->insert([...$values, 'id' => (string) Str::uuid()]), 1062, 'ai_pending_actions_turn_id_unique');
        $action = AiPendingAction::sole();
        $this->assertSame(['title' => 'Preserved'], $action->arguments);
        $this->assertSame('123456', $action->expires_at->format('u'));
        foreach ([['type' => 'delete_tasks'], ['status' => 'unknown'], ['status' => 'executed'],
            ['receipt' => '{}'], ['status' => 'rejected'], ['resolved_at' => '2026-10-23 12:00:00']] as $invalid) {
            $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update($invalid), 3819);
        }
        $conversation->delete();
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertDatabaseCount('ai_conversation_turns', 0);
    }

    public function test_migration_round_trip_is_additive_and_preserves_turns_and_messages(): void
    {
        [$conversation, $values] = $this->fixture();
        $message = $conversation->messages()->create(['role' => 'user', 'content' => 'Preserved'])->refresh();
        DB::table('ai_pending_actions')->insert($values);
        $before = [$conversation->getAttributes(), $message->getAttributes(), DB::table('ai_conversation_turns')->sole()];
        $this->artisan('migrate:rollback', ['--step' => 2])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('ai_pending_actions'));
        $this->assertEquals($before, [$conversation->fresh()->getAttributes(), $message->fresh()->getAttributes(), DB::table('ai_conversation_turns')->sole()]);
        $this->artisan('migrate')->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('ai_pending_actions'));
        $this->assertEquals($before, [$conversation->fresh()->getAttributes(), $message->fresh()->getAttributes(), DB::table('ai_conversation_turns')->sole()]);
    }
}
