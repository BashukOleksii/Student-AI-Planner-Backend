<?php

namespace Tests\Feature;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\TestCase;

class TaskMissedMigrationTest extends TestCase
{
    use AssertsMysqlPersistence, UsesForwardOnlyMigrations;

    public function test_upgrade_preserves_rows_enforces_all_three_states_and_refuses_lossy_rollback(): void
    {
        // Rebuild the immediate predecessor CHECKs only on the guarded disposable schema.
        DB::statement("ALTER TABLE tasks DROP CHECK tasks_valid_status, DROP CHECK tasks_completion_consistency,
            ADD CONSTRAINT tasks_valid_status CHECK (status IN ('pending', 'completed')),
            ADD CONSTRAINT tasks_completion_consistency CHECK ((status = 'completed' AND completed_at IS NOT NULL)
                OR (status = 'pending' AND completed_at IS NULL))");
        $owner = User::factory()->create();
        $pending = Task::factory()->for($owner)->create();
        Task::factory()->for($owner)->create(['status' => 'completed', 'completed_at' => '2026-11-01 10:00:00']);
        $deleted = Task::factory()->for($owner)->create();
        $deleted->delete();
        $before = DB::table('tasks')->orderBy('id')->get()->all();
        $this->assertMysqlRejects(fn () => DB::table('tasks')->where('id', $pending->id)->update(['status' => 'missed']), 3819);
        $migration = require database_path('migrations/2026_11_08_000001_add_missed_task_status.php');
        $migration->up();
        $this->assertEquals($before, DB::table('tasks')->orderBy('id')->get()->all());
        $missed = Task::factory()->for($owner)->create(['status' => 'missed']);
        $this->assertSame('missed', $missed->fresh()->status->value);
        $this->assertInsertAndUpdateRejected($missed, ['completed_at' => '2026-11-01 10:00:00'], 3819, 'tasks_completion_consistency');
        $this->assertInsertAndUpdateRejected($pending, ['status' => 'completed', 'completed_at' => null], 3819, 'tasks_completion_consistency');
        $this->assertInsertAndUpdateRejected($pending, ['completed_at' => '2026-11-01 10:00:00'], 3819, 'tasks_completion_consistency');
        $this->assertInsertAndUpdateRejected($pending, ['status' => 'overdue'], 3819);
        $this->assertInsertAndUpdateRejected(Subtask::factory()->for($pending)->create(), ['status' => 'missed'], 3819);
        $this->assertEnforcedChecks('tasks', ['tasks_valid_status', 'tasks_completion_consistency', 'tasks_valid_priority', 'tasks_positive_estimate']);
        $beforeDown = DB::table('tasks')->orderBy('id')->get()->all();
        try {
            $migration->down();
            $this->fail('Downgrade must refuse to discard or reinterpret missed work.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertEquals($beforeDown, DB::table('tasks')->orderBy('id')->get()->all());
    }
}
