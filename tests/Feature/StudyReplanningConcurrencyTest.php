<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningPreferenceService;
use App\Services\Planning\StudyAvailabilityService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningPersistenceService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReschedulingService;
use App\Services\Schedule\LessonService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StudyReplanningConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_two_blocked_confirmations_apply_exactly_one_multi_source_batch(): void
    {
        [$owner, $task, $source] = $this->fixture();
        $second = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        $preview = $this->preview($owner);
        $workers = [];
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            foreach (range(1, 2) as $unused) {
                $worker = $this->worker($owner, $preview['preview_token']);
                $workers[] = $worker;
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
            DB::commit();
            $statuses = array_map(fn ($worker) => $this->outcome($worker)['status'], $workers);
            sort($statuses);
            $this->assertSame(['applied', 'conflict'], $statuses);
            $this->assertDatabaseCount('study_sessions', 4);
            foreach ([$source, $second] as $original) {
                $this->assertSame(StudySessionStatus::Rescheduled, $original->fresh()->status);
                $this->assertSame(1, StudySession::where('rescheduled_from_session_id', $original->id)->count());
            }
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
    public function test_waiter_regenerates_after_actual_cooperating_mutation_commits(string $mutation): void
    {
        [$owner, $task, $source, $lesson, $window] = $this->fixture();
        $child = null;
        if ($mutation === 'subtask delete') {
            $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 240]);
            $source->update(['subtask_id' => $child->id]);
        }
        if ($mutation === 'complete') {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T09:59:00Z'));
        }
        $preview = $this->preview($owner);
        $worker = null;
        $spawn = function () use ($owner, $preview, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, $preview['preview_token'], ['now' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z')]);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        };
        $model = match ($mutation) {
            'lesson' => Lesson::class, 'preferences' => PlanningPreference::class, 'availability' => StudyAvailabilityWindow::class,
            'task delete' => Task::class, 'subtask delete' => Subtask::class, default => StudySession::class,
        };
        if (str_ends_with($mutation, 'delete')) {
            $model::deleted($spawn);
        } elseif ($mutation === 'plan' || $mutation === 'preferences') {
            $model::created($spawn);
        } else {
            $model::updated($spawn);
        }
        try {
            match ($mutation) {
                'cancel' => app(StudySessionLifecycleService::class)->cancel($owner, (string) $source->id),
                'complete' => (function () use ($owner, $source) {
                    $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
                    app(StudySessionLifecycleService::class)->complete($owner, (string) $source->id, 45);
                })(),
                'reschedule' => app(StudySessionReschedulingService::class)->reschedule($owner, (string) $source->id, '2026-10-19T13:00:00Z', '2026-10-19T14:00:00Z'),
                'task delete' => app(TaskService::class)->delete($owner, $task),
                'subtask delete' => app(SubtaskService::class)->delete($owner, $task, $child),
                'lesson' => app(LessonService::class)->update($owner, $lesson, ['starts_at' => '2026-10-19T09:00:00Z', 'ends_at' => '2026-10-19T10:00:00Z']),
                'preferences' => app(PlanningPreferenceService::class)->replace($owner, ['min_session_minutes' => null, 'max_session_minutes' => 30,
                    'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null]),
                'availability' => app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '09:30:00']),
                'plan' => (function () use ($owner) {
                    $plan = app(StudyPlanPersistenceService::class)->preview($owner, '2026-10-19', '2026-10-19');
                    app(StudyPlanPersistenceService::class)->confirm($owner, $plan['preview_token']);
                })(),
            };
            $this->assertNotNull($worker);
            $this->assertSame('conflict', $this->outcome($worker)['status']);
            $this->assertSame($mutation === 'reschedule' ? 1 : 0, StudySession::where('rescheduled_from_session_id', $source->id)->count());
        } finally {
            $model::flushEventListeners();
            $worker?->stop();
        }
    }

    public static function mutations(): array
    {
        return array_map(fn ($action) => [$action], ['cancel', 'complete', 'reschedule', 'task delete', 'subtask delete', 'lesson', 'preferences', 'availability', 'plan']);
    }

    public function test_stage_c_waiter_observes_committed_e2_replacements(): void
    {
        [$owner] = $this->fixture();
        $preview = $this->preview($owner);
        $plan = app(StudyPlanPersistenceService::class)->preview($owner, '2026-10-19', '2026-10-19');
        $worker = null;
        StudySession::updated(function () use ($owner, $plan, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, $plan['preview_token'], ['action' => 'plan']);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        });
        try {
            app(StudyReplanningPersistenceService::class)->confirm($owner, $preview['preview_token']);
            $this->assertSame('conflict', $this->outcome($worker)['status']);
            $this->assertDatabaseCount('study_sessions', 2);
        } finally {
            StudySession::flushEventListeners();
            $worker?->stop();
        }
    }

    public function test_blocked_worker_observes_rollback_of_partially_written_e2_batch(): void
    {
        [$owner] = $this->fixture();
        $preview = $this->preview($owner);
        $worker = null;
        StudySession::updated(function () use ($owner, $preview, &$worker): void {
            $worker = $this->worker($owner, $preview['preview_token']);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            throw new RuntimeException('Rollback predecessor write.');
        });
        try {
            try {
                app(StudyReplanningPersistenceService::class)->confirm($owner, $preview['preview_token']);
                $this->fail('Injected rollback did not run.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Rollback predecessor write.', $exception->getMessage());
            }
            $this->assertSame('applied', $this->outcome($worker)['status']);
            $this->assertDatabaseCount('study_sessions', 2);
        } finally {
            StudySession::flushEventListeners();
            $worker?->stop();
        }
    }

    #[DataProvider('clockAfterWait')]
    public function test_real_lock_wait_rechecks_source_future_and_token_expiry(string $issued, string $after, string $status): void
    {
        [$owner, , $source] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse($issued));
        $preview = $this->preview($owner);
        $worker = null;
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            $worker = $this->worker($owner, $preview['preview_token'], ['now' => $issued, 'now_after_lock' => $after]);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            DB::commit();
            $this->assertSame($status, $this->outcome($worker)['status']);
            $this->assertSame(StudySessionStatus::Planned, $source->fresh()->status);
            $this->assertDatabaseCount('study_sessions', 1);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            } $worker?->stop();
        }
    }

    public static function clockAfterWait(): array
    {
        return [['2026-10-19T09:59:00Z', '2026-10-19T10:00:00.000001Z', 'conflict'],
            ['2026-10-19T08:00:00Z', '2026-10-19T08:15:00Z', 'invalid']];
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 240]);
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $lesson = Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $window = StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);

        return [$owner, $task, $source, $lesson, $window];
    }

    private function preview(User $owner): array
    {
        return app(StudyReplanningPersistenceService::class)->preview($owner, '2026-10-19', '2026-10-19');
    }

    private function worker(User $owner, string $token, array $parameters = []): Process
    {
        $config = DB::connection()->getConfig();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/study_replanning_confirmation_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => 'student_planner_testing',
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => $config['unix_socket'] ?? '',
        ]);
        $worker->setInput(json_encode(['owner_id' => $owner->id, 'preview_token' => $token, 'now' => '2026-10-19T08:00:00.500000Z', ...$parameters], JSON_THROW_ON_ERROR));
        $worker->setTimeout(15);
        $worker->start();

        return $worker;
    }

    private function readyConnection(Process $worker): int
    {
        $deadline = microtime(true) + 5;
        do {
            $ready = json_decode(explode("\n", $worker->getOutput())[0], true);
            if (is_array($ready) && isset($ready['connection_id'])) {
                $this->assertTrue($ready['ready']);

                return (int) $ready['connection_id'];
            }
            if (! $worker->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('The independent worker did not become ready: '.$worker->getErrorOutput());
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
        $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
        $lines = explode("\n", trim($worker->getOutput()));
        $result = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('status', $result);

        return $result;
    }
}
