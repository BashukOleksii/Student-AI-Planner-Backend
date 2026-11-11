<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Services\AI\Actions\AvailabilityActionRegistry;
use App\Services\AI\Actions\LessonActionRegistry;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\AI\Actions\TaskActionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\TestCase;

#[Group('mysql')]
#[Group('ai')]
#[Group('migration')]
class AiAvailabilityActionMigrationTest extends TestCase
{
    use AssertsMysqlPersistence, UsesForwardOnlyMigrations;

    private function checks(): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS name, CHECK_CLAUSE AS clause
            FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'ai_actions_%'
            ORDER BY CONSTRAINT_NAME
            SQL))->pluck('clause', 'name')->all();
    }

    public function test_forward_upgrade_preserves_all_legacy_actions_receipts_and_unrelated_constraints(): void
    {
        // Restore the 18-type predecessor only on this guarded disposable test schema.
        (require database_path('migrations/2026_11_02_000001_add_ai_lesson_actions.php'))->up();
        $old = [...TaskActionRegistry::NAMES, ...SubtaskReminderActionRegistry::NAMES,
            'propose_study_plan_create', 'propose_adaptive_replanning', 'propose_session_reschedule', ...LessonActionRegistry::NAMES];
        $this->assertCount(18, $old);
        $conversation = AiConversation::factory()->create();
        foreach ($old as $i => $type) {
            $turn = DB::table('ai_conversation_turns')->insertGetId(['conversation_id' => $conversation->id,
                'request_id' => (string) Str::uuid(), 'input_hash' => hash('sha256', $type), 'result' => '{}']);
            AiPendingAction::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'turn_id' => $turn,
                'call_id' => 'legacy_'.$i, 'type' => $type, 'arguments' => ['preserved' => $i], 'preview' => ['original' => true],
                'expires_at' => now('UTC')->addMinutes(15), 'status' => 'executed', 'resolved_at' => now('UTC'),
                'receipt' => ['action_type' => $type, 'historical_window_id' => $i + 1]]);
        }
        $action = AiPendingAction::firstOrFail();
        $rows = DB::table('ai_pending_actions')->orderBy('id')->get()->all();
        $checks = $this->checks();
        $schema = [Schema::getColumns('ai_pending_actions'), Schema::getForeignKeys('ai_pending_actions'), Schema::getIndexes('ai_pending_actions')];
        foreach (AvailabilityActionRegistry::NAMES as $type) {
            $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => $type]), 3819, 'ai_actions_valid_type');
        }
        $migration = require database_path('migrations/2026_11_08_000002_add_ai_availability_actions.php');
        $migration->up();
        foreach (['propose_session_mark_missed', 'propose_session_complete'] as $futureType) {
            $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => $futureType]), 3819, 'ai_actions_valid_type');
        }
        $this->assertEquals($rows, DB::table('ai_pending_actions')->orderBy('id')->get()->all());
        $this->assertSame($schema, [Schema::getColumns('ai_pending_actions'), Schema::getForeignKeys('ai_pending_actions'), Schema::getIndexes('ai_pending_actions')]);
        $after = $this->checks();
        $this->assertNotSame($checks['ai_actions_valid_type'], $after['ai_actions_valid_type']);
        unset($checks['ai_actions_valid_type'], $after['ai_actions_valid_type']);
        $this->assertSame($checks, $after);
        $this->assertEnforcedChecks('ai_pending_actions', ['ai_actions_valid_type', 'ai_actions_valid_status', 'ai_actions_receipt_state', 'ai_actions_resolution_state']);
        $types = [...$old, ...AvailabilityActionRegistry::NAMES];
        $this->assertCount(21, $types);
        foreach ($types as $type) {
            $this->assertLessThanOrEqual(32, strlen($type));
            DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => $type]);
            $this->assertSame($type, $action->fresh()->type);
        }
        foreach (['delete_availability', 'propose_availability_bulk_delete', 'unknown'] as $bad) {
            $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => $bad]), 3819, 'ai_actions_valid_type');
        }
        foreach ([['status' => 'unknown'], ['receipt' => null], ['resolved_at' => null], ['status' => 'pending']] as $bad) {
            $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update($bad), 3819);
        }
        $beforeDown = DB::table('ai_pending_actions')->orderBy('id')->get()->all();
        try {
            $migration->down();
            $this->fail('Availability action history cannot be downgraded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertEquals($beforeDown, DB::table('ai_pending_actions')->orderBy('id')->get()->all());
        $this->assertSame('propose_availability_delete', $action->fresh()->type);
    }
}
