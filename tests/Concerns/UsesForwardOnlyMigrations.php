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

    /** Keep legacy down/up coverage without downgrading forward-only overlays. */
    protected function excludeForwardOnlyOverlayFromLegacyRollback(): int
    {
        // Only this guarded disposable test schema is involved. Legacy down resets
        // the underlying CHECK/table; migrate then reapplies the overlays.
        return DB::table('migrations')
            ->whereIn('migration', ['2026_11_02_000001_add_ai_lesson_actions', '2026_11_08_000001_add_missed_task_status', '2026_11_08_000002_add_ai_availability_actions'])->delete();
    }
}
