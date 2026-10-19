<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

class AiManagementActionMigrationTest extends TestCase
{
    use AssertsMysqlPersistence, DatabaseMigrations;

    public function test_expansion_and_rollback_preserve_existing_task_history_and_reject_unknown_types(): void
    {
        $conversation = AiConversation::factory()->create();
        $turn = DB::table('ai_conversation_turns')->insertGetId(['conversation_id' => $conversation->id,
            'request_id' => (string) Str::uuid(), 'input_hash' => hash('sha256', 'question'), 'result' => '{}']);
        $id = (string) Str::uuid();
        $values = ['id' => $id, 'conversation_id' => $conversation->id, 'turn_id' => $turn, 'call_id' => 'original',
            'type' => 'propose_task_create', 'arguments' => '{"title":"Original"}', 'preview' => '{"confirmation_required":true}',
            'expires_at' => '2026-10-24 12:00:00.123456'];
        DB::table('ai_pending_actions')->insert($values);
        $before = DB::table('ai_pending_actions')->sole();
        $migration = require database_path('migrations/2026_10_19_000001_expand_ai_pending_action_types.php');
        $this->assertTrue(Schema::hasIndex('ai_conversations', 'ai_conversations_owner_reference_index'));
        $this->assertTrue(Schema::hasIndex('ai_conversations', ['user_id', 'updated_at']));
        $migration->down();
        $this->assertFalse(Schema::hasIndex('ai_conversations', 'ai_conversations_owner_reference_index'));
        $this->assertTrue(Schema::hasIndex('ai_conversations', ['user_id', 'updated_at']));
        $this->assertEquals($before, DB::table('ai_pending_actions')->sole());
        $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $id)->update(['type' => 'propose_subtask_create']), 3819);
        $migration->up();
        $this->assertTrue(Schema::hasIndex('ai_conversations', 'ai_conversations_owner_reference_index'));
        $this->assertEquals($before, DB::table('ai_pending_actions')->sole());
        foreach (['propose_subtask_create', 'propose_subtask_update', 'propose_subtask_complete', 'propose_subtask_reopen',
            'propose_reminder_create', 'propose_reminder_update', 'propose_reminder_cancel'] as $type) {
            DB::table('ai_pending_actions')->where('id', $id)->update(['type' => $type]);
            $this->assertSame($type, DB::table('ai_pending_actions')->sole()->type);
        }
        $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $id)->update(['type' => 'delete_tasks']), 3819);
        try {
            $migration->down();
            $this->fail('Downgrade must preserve unsupported action history rather than deleting it.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot downgrade action types while H3b action history exists.', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasIndex('ai_conversations', 'ai_conversations_owner_reference_index'));
        $this->assertSame('propose_reminder_cancel', DB::table('ai_pending_actions')->sole()->type);
        DB::table('ai_pending_actions')->where('id', $id)->update(['type' => 'propose_task_create']);
        $migration->down();
        $migration->up();
        $this->assertEquals($before, DB::table('ai_pending_actions')->sole());
    }
}
