<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Schedule\LessonService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StudyPlanConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_simultaneous_confirmations_contend_on_real_owner_lock_and_only_one_batch_commits(): void
    {
        [$user] = $this->fixture();
        $preview = app(StudyPlanPersistenceService::class)->preview($user, '2026-10-19', '2026-10-19');
        $workers = [];
        DB::beginTransaction();
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        try {
            foreach (range(1, 2) as $index) {
                $worker = $this->worker($user, $preview['preview_token']);
                $workers[] = $worker;
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
            $this->assertDatabaseCount('study_sessions', 0);
            DB::commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $statuses[] = $this->outcome($worker)['status'];
            }
            sort($statuses);
            $this->assertSame(['conflict', 'created'], $statuses);
            $this->assertDatabaseCount('study_sessions', 2);
            $this->assertSame(['2026-10-19 09:00:00', '2026-10-19 09:30:00'],
                StudySession::orderBy('starts_at')->pluck('starts_at')->map(fn ($time) => $time->format('Y-m-d H:i:s'))->all());
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    #[DataProvider('mutations')]
    public function test_confirmation_waits_for_cooperating_mutation_then_reads_committed_changed_state(string $mutation): void
    {
        [$user, $task] = $this->fixture();
        $lesson = Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $preview = app(StudyPlanPersistenceService::class)->preview($user, '2026-10-19', '2026-10-19');
        $worker = null;
        DB::beginTransaction();
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        try {
            $worker = $this->worker($user, $preview['preview_token']);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            // Actual application Services cooperate with the owner-first lock. A session insert
            // follows the same protocol to model another reservation writer.
            match ($mutation) {
                'task' => app(TaskService::class)->update($user, $task, ['estimated_minutes' => 90]),
                'lesson' => app(LessonService::class)->update($user, $lesson,
                    ['starts_at' => '2026-10-19T09:00:00Z', 'ends_at' => '2026-10-19T10:00:00Z']),
                'session' => StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 09:30:00']),
                'timezone' => $user->update(['timezone' => 'Europe/Kyiv']),
            };
            DB::commit();
            $this->assertSame('conflict', $this->outcome($worker)['status']);
            $this->assertDatabaseCount('study_sessions', $mutation === 'session' ? 1 : 0);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            $worker?->stop();
        }
    }

    public static function mutations(): array
    {
        return [['task'], ['lesson'], ['session'], ['timezone']];
    }

    private function fixture(): array
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
        $user = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => 60]);
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']);
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30]);

        return [$user, $task];
    }

    private function worker(User $user, string $token): Process
    {
        $config = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/study_plan_confirmation_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => 'student_planner_testing', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'DB_SOCKET' => $config['unix_socket'] ?? '',
        ]);
        $process->setInput(json_encode(['owner_id' => $user->id, 'preview_token' => $token, 'now' => '2026-10-19T08:00:00Z'], JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        $process->start();

        return $process;
    }

    private function readyConnection(Process $worker): int
    {
        $deadline = microtime(true) + 5;
        do {
            $line = explode("\n", $worker->getOutput())[0];
            $ready = json_decode($line, true);
            if (is_array($ready) && isset($ready['connection_id'])) {
                $this->assertTrue($ready['ready']);

                return (int) $ready['connection_id'];
            }
            if (! $worker->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('The independent MySQL worker did not become ready: '.$worker->getErrorOutput());
    }

    private function assertWaitingOnOwner(int $connectionId): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne(<<<'SQL'
                SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits AS waits
                JOIN performance_schema.threads AS threads ON waits.REQUESTING_THREAD_ID = threads.THREAD_ID
                JOIN performance_schema.data_locks AS locks ON waits.REQUESTING_ENGINE_LOCK_ID = locks.ENGINE_LOCK_ID
                WHERE threads.PROCESSLIST_ID = ? AND locks.OBJECT_SCHEMA = 'student_planner_testing' AND locks.OBJECT_NAME = 'users'
                SQL, [$connectionId]);
            if ((int) $waiting->total > 0) {
                $this->assertGreaterThan(0, (int) $waiting->total);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('The worker did not exhibit a real MySQL owner-row lock wait.');
    }

    private function outcome(Process $worker): array
    {
        $exit = $worker->wait();
        $this->assertSame(0, $exit, $worker->getErrorOutput());
        $lines = explode("\n", trim($worker->getOutput()));
        $outcome = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('status', $outcome);

        return $outcome;
    }
}
