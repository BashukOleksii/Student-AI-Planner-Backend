<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Services\AI\Actions\PlanningActionRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

class AiPlanningActionMigrationTest extends TestCase
{
    use AssertsMysqlPersistence, DatabaseMigrations;

    public function test_reversible_addition_preserves_existing_receipts_and_protects_new_private_history(): void
    {
        $conversation = AiConversation::factory()->create();
        $turn = DB::table('ai_conversation_turns')->insertGetId(['conversation_id' => $conversation->id,
            'request_id' => (string) Str::uuid(), 'input_hash' => hash('sha256', 'q'), 'result' => '{}']);
        $action = AiPendingAction::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'turn_id' => $turn,
            'call_id' => 'original', 'type' => 'propose_task_create', 'arguments' => ['title' => 'Original'], 'preview' => [],
            'expires_at' => now('UTC')->addMinutes(15), 'status' => 'executed', 'resolved_at' => now('UTC'),
            'receipt' => ['task' => ['id' => 1, 'title' => 'Historical']]])->refresh();
        $before = $action->getRawOriginal();
        $migration = require database_path('migrations/2026_10_19_000002_add_ai_planning_actions.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('ai_pending_actions', 'protected_payload'));
        $this->assertEquals(array_diff_key($before, ['protected_payload' => true]), $action->fresh()->getRawOriginal());
        $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => 'propose_study_plan_create']), 3819);
        $migration->up();
        $this->assertSame($before, $action->fresh()->getRawOriginal());
        foreach (PlanningActionRegistry::NAMES as $type) {
            DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => $type]);
            $this->assertSame($type, $action->fresh()->type);
        }
        try {
            $migration->down();
            $this->fail('New action history must prevent downgrade.');
        } catch (RuntimeException) {
            $this->assertTrue(Schema::hasColumn('ai_pending_actions', 'protected_payload'));
        }
        $action->refresh()->update(['type' => 'propose_task_create', 'protected_payload' => ['preview_token' => 'private-test-payload']]);
        $this->assertStringNotContainsString('private-test-payload', $action->getRawOriginal('protected_payload'));
        $this->assertArrayNotHasKey('protected_payload', $action->toArray());
        try {
            $migration->down();
            $this->fail('Private metadata must prevent downgrade even on a legacy type.');
        } catch (RuntimeException) {
            $this->assertSame('private-test-payload', $action->fresh()->protected_payload['preview_token']);
        }
        $action->refresh()->update(['protected_payload' => null]);
        $migration->down();
        $migration->up();
        $this->assertEquals($before['receipt'], $action->fresh()->getRawOriginal('receipt'));
        $this->assertTrue(Schema::hasIndex('ai_conversations', 'ai_conversations_owner_reference_index'));
    }
}
