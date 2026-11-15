<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

trait UsesForwardOnlyMigrations
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('db:wipe');
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function migrateThrough(string $lastMigration, bool $fresh = false): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path, '.php') <= $lastMigration));
        $this->assertNotEmpty($paths);
        $this->assertSame($lastMigration.'.php', basename(end($paths)));
        $this->artisan($fresh ? 'migrate:fresh' : 'migrate', ['--path' => $paths, '--realpath' => true])->assertExitCode(0);
    }
}
