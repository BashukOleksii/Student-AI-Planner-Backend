<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\Concerns\InteractsWithLearningRecovery;
use Tests\TestCase;

#[Group('mysql')]
#[Group('migration')]
#[Group('recovery')]
class RecoverySuggestionPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, DatabaseTruncation, InteractsWithLearningRecovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeRecovery();
    }

    public function test_current_mysql_constraints_encrypt_tokens_and_enforce_review_lifecycle_and_uniqueness(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $this->assertEnforcedChecks('recovery_suggestions', ['recovery_suggestions_kind', 'recovery_suggestions_status',
            'recovery_suggestions_active', 'recovery_suggestions_receipt', 'recovery_suggestions_resolution']);
        $this->assertForeignKeys('recovery_suggestions', ['user_id' => ['users', 'cascade']]);
        $this->assertIndex('recovery_suggestions', 'recovery_suggestions_active_owner_id_unique', ['active_owner_id'], true);
        $this->assertIndex('recovery_suggestions', 'recovery_suggestions_dedupe_key_unique', ['dedupe_key'], true);
        $this->assertIndex('recovery_suggestions', 'recovery_suggestions_refreshed_from_id_unique', ['refreshed_from_id'], true);
        $this->assertNotSame($row->protected_token, $row->getRawOriginal('protected_token'));
        $this->assertSame($row->protected_token, $row->fresh()->protected_token);
        $invalid = [['kind' => 'invented'], ['status' => 'invented'], ['active_owner_id' => null],
            ['active_owner_id' => User::factory()->create()->id], ['receipt' => '{}'],
            ['resolved_at' => '2026-10-19 08:00:00'],
            ['status' => 'applied', 'active_owner_id' => null, 'resolved_at' => '2026-10-19 08:00:00', 'receipt' => null]];
        foreach ($invalid as $changes) {
            $this->assertMysqlRejects(fn () => DB::table('recovery_suggestions')->where('id', $row->id)->update($changes), 3819);
        }
        $values = $row->getAttributes();
        $this->assertMysqlRejects(fn () => DB::table('recovery_suggestions')->insert([...$values,
            'id' => (string) Str::uuid(), 'dedupe_key' => hash('sha256', 'second active')]), 1062);
        $f['service']->reject($f['owner'], $row->id);
        $resolved = $row->fresh()->getAttributes();
        $this->assertMysqlRejects(fn () => DB::table('recovery_suggestions')->insert([...$resolved,
            'id' => (string) Str::uuid()]), 1062);
        $this->assertMysqlRejects(fn () => DB::table('recovery_suggestions')->insert([...$resolved,
            'id' => (string) Str::uuid(), 'dedupe_key' => hash('sha256', 'foreign'), 'user_id' => 999999]), 1452);
        $this->assertDatabaseCount('recovery_suggestions', 1);
    }

    public function test_scan_state_foreign_keys_and_refresh_lineage_uniqueness_are_enforced(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $child = $f['service']->refresh($f['owner'], $row->id);
        $values = $child->getAttributes();
        $this->assertMysqlRejects(fn () => DB::table('recovery_suggestions')->insert([...$values,
            'id' => (string) Str::uuid(), 'dedupe_key' => hash('sha256', 'duplicate lineage'),
            'status' => 'rejected', 'active_owner_id' => null, 'resolved_at' => '2026-10-19 08:00:00', 'protected_token' => null]), 1062);
        $this->assertForeignKeys('recovery_scan_states', ['user_id' => ['users', 'cascade']]);
        $this->assertMysqlRejects(fn () => DB::table('recovery_scan_states')->insert(['user_id' => $f['owner']->id]), 1062);
        $this->assertMysqlRejects(fn () => DB::table('recovery_scan_states')->insert(['user_id' => 999999]), 1452);
        $this->assertTrue(collect(Schema::getIndexes('recovery_scan_states'))->contains(fn ($index) => $index['primary'] && $index['columns'] === ['user_id']));
    }

    public function test_forward_only_migration_refuses_without_touching_reviews_or_scan_history(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $before = [DB::table('recovery_suggestions')->get()->toJson(), DB::table('recovery_scan_states')->get()->toJson()];
        try {
            (require database_path('migrations/2026_11_12_000002_create_recovery_suggestions.php'))->down();
            $this->fail('Recovery history must not be dropped by a downgrade.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertSame($before, [DB::table('recovery_suggestions')->get()->toJson(), DB::table('recovery_scan_states')->get()->toJson()]);
    }
}
