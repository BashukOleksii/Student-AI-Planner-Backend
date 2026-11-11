<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithLearningRecovery;
use Tests\Concerns\InteractsWithMysqlWorkers;
use Tests\TestCase;

#[Group('mysql')]
#[Group('concurrency')]
#[Group('recovery')]
class AutomaticRecoveryConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithLearningRecovery, InteractsWithMysqlWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeRecovery();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    public function test_two_real_blocked_confirmations_apply_once_and_replay_the_same_immutable_receipt(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $workers = [];
        DB::beginTransaction();
        User::whereKey($f['owner']->id)->lockForUpdate()->firstOrFail();
        try {
            foreach (range(1, 2) as $unused) {
                $worker = $this->startMysqlWorker('recovery_confirmation_worker.php', ['owner_id' => $f['owner']->id,
                    'suggestion_id' => $row->id, 'now' => '2026-10-19T08:00:00Z']);
                $workers[] = $worker;
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
            DB::commit();
            $results = array_map(fn ($worker) => $this->outcome($worker), $workers);
            $this->assertSame(['applied', 'applied'], array_column($results, 'status'));
            $this->assertEqualsCanonicalizing([false, true], array_column($results, 'replayed'));
            $this->assertSame($results[0]['receipt_hash'], $results[1]['receipt_hash']);
            $this->assertSame(hash('sha256', json_encode($row->fresh()->receipt, JSON_THROW_ON_ERROR)), $results[0]['receipt_hash']);
            $this->assertDatabaseCount('study_sessions', 1);
            $this->assertSame('applied', $row->fresh()->status);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    #[DataProvider('changesWhileWaiting')]
    public function test_source_or_clock_changes_during_a_real_owner_lock_wait_prevent_application(string $change, string $expected): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        DB::beginTransaction();
        User::whereKey($f['owner']->id)->lockForUpdate()->firstOrFail();
        $worker = $this->startMysqlWorker('recovery_confirmation_worker.php', ['owner_id' => $f['owner']->id,
            'suggestion_id' => $row->id, 'now' => '2026-10-19T08:00:00Z',
            ...($change === 'expiry' ? ['now_after_lock' => '2026-10-19T08:15:00Z'] : [])]);
        try {
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            if ($change === 'deadline') {
                $f['task']->update(['deadline_at' => '2026-10-22 17:00:00']);
            }
            DB::commit();
            $this->assertSame($expected, $this->outcome($worker)['status']);
            $this->assertDatabaseCount('study_sessions', 0);
            $this->assertNull($row->fresh()->receipt);
            $this->assertSame($change === 'expiry' ? 'expired' : 'invalidated', $row->fresh()->status);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    public static function changesWhileWaiting(): array
    {
        return [['expiry', 'suggestion_expired'], ['deadline', 'suggestion_stale']];
    }
}
