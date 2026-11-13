<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
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
class AiSemesterOccurrenceCreateMigrationTest extends TestCase
{
    use AssertsMysqlPersistence, UsesForwardOnlyMigrations;

    private const TYPE = 'propose_semester_occurrence_create';

    public function test_clean_migration_chain_accepts_current_and_historical_types_and_rejects_unknown_types(): void
    {
        $conversation = AiConversation::factory()->create();
        // Retired tools may still have historical pending actions and receipts.
        $types = [...array_filter(AiCapabilities::names(), fn ($name) => str_starts_with($name, 'propose_')), 'propose_lesson_replacement'];
        $this->assertEqualsCanonicalizing($types, $this->allowedTypes());
        foreach ($types as $type) {
            $this->assertSame($type, $this->action($conversation, $type)->fresh()->type);
        }
        $this->assertMysqlRejects(fn () => $this->action($conversation, 'unregistered_write'), 3819, 'ai_actions_valid_type');
        $this->assertEnforcedChecks('ai_pending_actions', ['ai_actions_valid_type', 'ai_actions_valid_status',
            'ai_actions_receipt_state', 'ai_actions_resolution_state']);
    }

    public function test_upgrade_adds_only_one_type_preserves_populated_history_and_refuses_rollback(): void
    {
        $this->migrateThrough('2026_11_13_000002_add_recovery_check_delivery_state', true);
        $oldTypes = $this->allowedTypes();
        $this->assertNotContains(self::TYPE, $oldTypes);
        $conversation = AiConversation::factory()->create();
        foreach ($oldTypes as $index => $type) {
            $this->action($conversation, $type, ['pending', 'executed', 'rejected', 'expired'][$index % 4]);
        }
        $this->assertMysqlRejects(fn () => $this->action($conversation, self::TYPE), 3819, 'ai_actions_valid_type');
        $before = $this->rows();
        $schema = $this->schema();
        $checks = $this->checks();
        unset($checks['ai_actions_valid_type']);

        $this->migrateThrough('2026_11_13_000003_add_ai_semester_occurrence_create_action');

        $this->assertEquals($before, $this->rows());
        $this->assertSame($schema, $this->schema());
        $afterChecks = $this->checks();
        $this->assertSame('YES', $afterChecks['ai_actions_valid_type']['enforced']);
        unset($afterChecks['ai_actions_valid_type']);
        $this->assertSame($checks, $afterChecks);
        $this->assertEqualsCanonicalizing([...$oldTypes, self::TYPE], $this->allowedTypes());
        foreach ([...$oldTypes, self::TYPE] as $type) {
            $this->assertSame($type, $this->action($conversation, $type)->fresh()->type);
        }
        $this->assertMysqlRejects(fn () => $this->action($conversation, 'unregistered_write'), 3819, 'ai_actions_valid_type');
        $sample = AiPendingAction::where('type', self::TYPE)->firstOrFail();
        $this->assertMysqlRejects(fn () => $sample->update(['type' => 'unregistered_write']), 3819, 'ai_actions_valid_type');
        $beforeDown = $this->rows();
        $checksBeforeDown = $this->checks();
        $migration = require database_path('migrations/2026_11_13_000003_add_ai_semester_occurrence_create_action.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve pending actions and executed receipts.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertEquals($beforeDown, $this->rows());
        $this->assertSame($checksBeforeDown, $this->checks());
    }

    private function action(AiConversation $conversation, string $type, string $status = 'pending'): AiPendingAction
    {
        $turn = DB::table('ai_conversation_turns')->insertGetId(['conversation_id' => $conversation->id,
            'request_id' => (string) Str::uuid(), 'input_hash' => hash('sha256', $type)]);

        return AiPendingAction::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id,
            'turn_id' => $turn, 'call_id' => $type, 'type' => $type, 'status' => $status,
            'arguments' => ['retained' => $type], 'preview' => ['retained' => true],
            'protected_payload' => ['private' => $type], 'target_hash' => hash('sha256', $type),
            'expires_at' => now()->addMinutes(15), 'resolved_at' => $status === 'pending' ? null : now(),
            'receipt' => $status === 'executed' ? ['type' => $type, 'retained' => true] : null]);
    }

    private function rows(): array
    {
        return DB::table('ai_pending_actions')->orderBy('id')->get()->all();
    }

    private function schema(): array
    {
        return [Schema::getColumns('ai_pending_actions'), Schema::getIndexes('ai_pending_actions'), Schema::getForeignKeys('ai_pending_actions')];
    }

    private function allowedTypes(): array
    {
        // These identifiers contain no escapes; normalize MySQL metadata's escaped quotes.
        $clause = str_replace('\\', '', $this->checks()['ai_actions_valid_type']['clause']);
        preg_match_all("/'([^']+)'/", $clause, $matches);
        $this->assertNotEmpty($matches[1]);

        return $matches[1];
    }

    private function checks(): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT tc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS clause, tc.ENFORCED AS enforced
            FROM information_schema.TABLE_CONSTRAINTS tc
            JOIN information_schema.CHECK_CONSTRAINTS cc
              ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'ai_pending_actions'
              AND tc.CONSTRAINT_TYPE = 'CHECK'
            ORDER BY tc.CONSTRAINT_NAME
            SQL))->mapWithKeys(fn ($row) => [$row->name => ['clause' => $row->clause, 'enforced' => $row->enforced]])->all();
    }
}
