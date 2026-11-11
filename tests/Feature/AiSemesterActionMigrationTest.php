<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Services\AI\Actions\SemesterActionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\Support\AiCapabilities;
use Tests\TestCase;

#[Group('mysql')]
#[Group('migration')]
#[Group('ai')]
class AiSemesterActionMigrationTest extends TestCase
{
    use AssertsMysqlPersistence, UsesForwardOnlyMigrations;

    public function test_semester_type_expansion_preserves_history_schema_and_every_current_action_type(): void
    {
        $this->migrateThrough('2026_11_11_000005_add_ai_session_lifecycle_actions', true);
        $conversation = AiConversation::factory()->create();
        $types = array_values(array_filter(AiCapabilities::names(), fn ($name) => str_starts_with($name, 'propose_')));
        $legacy = array_diff($types, SemesterActionRegistry::NAMES);
        foreach ($legacy as $type) {
            $turn = DB::table('ai_conversation_turns')->insertGetId(['conversation_id' => $conversation->id, 'request_id' => (string) Str::uuid(), 'input_hash' => hash('sha256', $type)]);
            AiPendingAction::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'turn_id' => $turn,
                'call_id' => $type, 'type' => $type, 'arguments' => ['retained' => $type], 'preview' => ['retained' => true],
                'expires_at' => now()->addMinutes(15), 'status' => 'executed', 'resolved_at' => now(), 'receipt' => ['type' => $type]]);
        }
        $action = AiPendingAction::firstOrFail();
        foreach (SemesterActionRegistry::NAMES as $type) {
            $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => $type]), strlen($type) > 32 ? 1406 : 3819);
        }
        $rows = DB::table('ai_pending_actions')->orderBy('id')->get()->all();
        $schema = [Schema::getColumns('ai_pending_actions'), Schema::getIndexes('ai_pending_actions'), Schema::getForeignKeys('ai_pending_actions')];
        $migration = require database_path('migrations/2026_11_12_000001_add_ai_semester_schedule_actions.php');
        $migration->up();
        $lengthMigration = require database_path('migrations/2026_11_12_000003_expand_ai_action_type_length.php');
        $lengthMigration->up();
        foreach ($schema[0] as &$column) {
            if ($column['name'] === 'type') {
                $column['type'] = 'varchar(64)';
            }
        }
        unset($column);
        $this->assertEquals($rows, DB::table('ai_pending_actions')->orderBy('id')->get()->all());
        $this->assertSame($schema, [Schema::getColumns('ai_pending_actions'), Schema::getIndexes('ai_pending_actions'), Schema::getForeignKeys('ai_pending_actions')]);
        foreach ($types as $type) {
            DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => $type]);
            $this->assertSame($type, $action->fresh()->type);
        }
        $this->assertMysqlRejects(fn () => DB::table('ai_pending_actions')->where('id', $action->id)->update(['type' => 'unregistered_write']), 3819);
        $before = DB::table('ai_pending_actions')->orderBy('id')->get()->all();
        try {
            $migration->down();
            $this->fail('Retained semester action history cannot be downgraded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertEquals($before, DB::table('ai_pending_actions')->orderBy('id')->get()->all());
        try {
            $lengthMigration->down();
            $this->fail('Retained long action identities cannot be narrowed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertEquals($before, DB::table('ai_pending_actions')->orderBy('id')->get()->all());
    }
}
