<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesForwardOnlyMigrations as DatabaseMigrations;
use Tests\TestCase;

#[Group('mysql')]
#[Group('migration')]
class PlanningMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_reversible_legacy_baseline_can_be_rolled_back_and_reapplied_without_losing_existing_users(): void
    {
        $legacyBoundary = '2026_10_19_000002_add_ai_planning_actions';
        $this->migrateThrough($legacyBoundary, true);
        $scaffoldMigrations = [
            '0001_01_01_000000_create_users_table',
            '0001_01_01_000001_create_cache_table',
            '0001_01_01_000002_create_jobs_table',
            '2026_09_26_164204_create_personal_access_tokens_table',
        ];
        $scaffoldTables = ['migrations', 'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens'];
        $migrationsBeforeRollback = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        // Laravel lists all accessible schemas unless the current database is explicit.
        $tablesBeforeRollback = Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false);
        $domainTables = array_values(array_diff($tablesBeforeRollback, $scaffoldTables));
        $domainMigrationCount = count(array_diff($migrationsBeforeRollback, $scaffoldMigrations));

        // Guard the rollback boundary; future additive domain groups are counted automatically.
        $this->assertSame($scaffoldMigrations, array_slice($migrationsBeforeRollback, 0, count($scaffoldMigrations)));
        $this->assertGreaterThan(0, $domainMigrationCount);
        foreach (['planning_preferences', 'study_availability_windows', 'education_institutions', 'academic_periods', 'subjects', 'teachers', 'schedule_import_batches', 'schedule_import_rows', 'lessons', 'tasks', 'subtasks', 'study_sessions', 'reminders', 'ai_conversations', 'ai_messages', 'notifications'] as $table) {
            $this->assertContains($table, $domainTables);
        }
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);

        $this->artisan('migrate:rollback', ['--step' => $domainMigrationCount])->assertExitCode(0);

        $this->assertFalse(Schema::hasColumn('users', 'timezone'));
        foreach ($domainTables as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertEqualsCanonicalizing($scaffoldTables, Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false));
        $this->assertSame($scaffoldMigrations, DB::table('migrations')->orderBy('migration')->pluck('migration')->all());

        $this->migrateThrough($legacyBoundary);

        $this->assertSame('UTC', $user->refresh()->timezone);
        $this->assertEqualsCanonicalizing($tablesBeforeRollback, Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false));
        $this->assertSame($migrationsBeforeRollback, DB::table('migrations')->orderBy('migration')->pluck('migration')->all());
    }

    public function test_all_current_forward_migrations_upgrade_scaffold_without_losing_existing_users(): void
    {
        $expectedMigrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        $expectedTables = Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false);
        $this->migrateThrough('2026_09_26_164204_create_personal_access_tokens_table', true);
        $id = DB::table('users')->insertGetId(['name' => 'Retained student', 'email' => 'retained@example.test',
            'password' => bcrypt('test-password'), 'created_at' => now(), 'updated_at' => now()]);
        $before = (array) DB::table('users')->where('id', $id)->sole();
        $this->artisan('migrate')->assertExitCode(0);
        $after = (array) DB::table('users')->where('id', $id)->sole();
        $this->assertSame('UTC', $after['timezone']);
        unset($after['timezone']);
        $this->assertSame($before, $after);
        $this->assertSame($expectedMigrations, DB::table('migrations')->orderBy('migration')->pluck('migration')->all());
        $this->assertEqualsCanonicalizing($expectedTables, Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false));
        foreach (['recovery_suggestions', 'recovery_scan_states', 'semester_schedules', 'weekly_lesson_rules', 'ai_pending_actions'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }
}
