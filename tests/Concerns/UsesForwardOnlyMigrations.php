<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/** Disposable-schema tests rebuild rather than invoking unsupported production downgrades. */
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

    /** Keep prior down/up coverage without pretending to downgrade the Stage 11 overlay. */
    protected function excludeForwardOnlyOverlayFromLegacyRollback(): int
    {
        // Only this guarded disposable test schema is involved. The legacy down resets
        // the underlying CHECK/table; migrate then executes the Stage 11 up again.
        return DB::table('migrations')
            ->where('migration', '2026_11_02_000001_add_ai_lesson_actions')->delete();
    }
}
