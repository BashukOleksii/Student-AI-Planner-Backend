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

    public function test_slice_can_be_rolled_back_and_reapplied_without_losing_existing_users(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);

        $this->artisan('migrate:rollback', ['--step' => 3])->assertExitCode(0);

        $this->assertFalse(Schema::hasColumn('users', 'timezone'));
        $this->assertFalse(Schema::hasTable('planning_preferences'));
        $this->assertFalse(Schema::hasTable('study_availability_windows'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        foreach (['password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame(4, DB::table('migrations')->count());

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertSame('UTC', $user->refresh()->timezone);
        $this->assertTrue(Schema::hasTable('planning_preferences'));
        $this->assertTrue(Schema::hasTable('study_availability_windows'));
        $this->assertSame(7, DB::table('migrations')->count());
    }
}
