<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Services\AI\Actions\LessonActionRegistry;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\AI\Actions\TaskActionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\TestCase;

class AiLessonActionMigrationTest extends TestCase
{
    use AssertsMysqlPersistence, UsesForwardOnlyMigrations;

    public function test_forward_migration_preserves_legacy_receipts_accepts_exactly_eighteen_types_and_refuses_downgrade(): void
    {
        $conversation = AiConversation::factory()->create();
        $turn = DB::table('ai_conversation_turns')->insertGetId(['conversation_id' => $conversation->id,
            'request_id' => (string) Str::uuid(), 'input_hash' => hash('sha256', 'q'), 'result' => '{}']);
        $action = AiPendingAction::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'turn_id' => $turn,
            'call_id' => 'original', 'type' => 'propose_task_create', 'arguments' => ['title' => 'Original'], 'preview' => [],
            'expires_at' => now('UTC')->addMinutes(15), 'status' => 'executed', 'resolved_at' => now('UTC'),
            'receipt' => ['task' => ['id' => 1, 'title' => 'Historical']]])->refresh();
        // Restore the immediate predecessor on this disposable schema to exercise the real upgrade.
        (require database_path('migrations/2026_10_19_000002_add_ai_planning_actions.php'))->down();
        (require database_path('migrations/2026_10_19_000002_add_ai_planning_actions.php'))->up();
        $before = $action->fresh()->getRawOriginal();
        $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => 'propose_lesson_create']), 3819);
        $migration = require database_path('migrations/2026_11_02_000001_add_ai_lesson_actions.php');
        $migration->up();
        foreach (['propose_session_mark_missed', 'propose_session_complete'] as $futureType) {
            $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => $futureType]), 3819, 'ai_actions_valid_type');
        }
        $this->assertSame($before, $action->fresh()->getRawOriginal());
        foreach ([...TaskActionRegistry::NAMES, ...SubtaskReminderActionRegistry::NAMES, 'propose_study_plan_create', 'propose_adaptive_replanning', 'propose_session_reschedule', ...LessonActionRegistry::NAMES] as $type) {
            DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => $type]);
            $this->assertSame($type, $action->fresh()->type);
        }
        $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => 'delete_lessons']), 3819);
        try {
            $migration->down();
            $this->fail('Stage 11 cannot be downgraded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertSame('propose_lesson_replacement', $action->fresh()->type);
        $this->assertSame($before['receipt'], $action->fresh()->getRawOriginal('receipt'));
    }
}
