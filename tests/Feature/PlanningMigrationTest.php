<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlanningMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_domain_migrations_can_be_rolled_back_and_reapplied_without_losing_existing_users(): void
    {
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
        foreach (['planning_preferences', 'study_availability_windows', 'education_institutions', 'academic_periods', 'subjects', 'teachers'] as $table) {
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

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertSame('UTC', $user->refresh()->timezone);
        $this->assertEqualsCanonicalizing($tablesBeforeRollback, Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false));
        $this->assertSame($migrationsBeforeRollback, DB::table('migrations')->orderBy('migration')->pluck('migration')->all());
    }
}
