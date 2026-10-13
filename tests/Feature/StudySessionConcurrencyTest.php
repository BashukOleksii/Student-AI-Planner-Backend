<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Enums\StudySessionStatus;
use App\Models\Lesson;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningWorkSelectionService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReschedulingService;
use App\Services\Schedule\LessonService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StudySessionConcurrencyTest extends TestCase
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

    #[DataProvider('competingActions')]
    public function test_real_competing_mutations_commit_only_one_transition(string $first, string $second, string $now, string $success): void
    {
        [$owner, $task, $source] = $this->fixture();
        $workers = [];
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ([$first, $second] as $action) {
                $worker = $this->worker($owner, ['action' => $action, 'session_id' => $source->id, 'now' => $now]);
                $workers[] = $worker;
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
            DB::commit();
            $statuses = array_map(fn ($worker) => $this->outcome($worker)['status'], $workers);
            sort($statuses);
            $expected = ['conflict', $success];
            sort($expected);
            $this->assertSame($expected, $statuses);
            $this->assertDatabaseCount('study_sessions', $success === 'created' ? 2 : 1);
            $this->assertSame($success === 'created' ? StudySessionStatus::Rescheduled : StudySessionStatus::Completed, $source->fresh()->status);
            if ($success === 'created') {
                $this->assertSame(1, StudySession::where('rescheduled_from_session_id', $source->id)->count());
            } else {
                $this->assertSame(45, $source->fresh()->actual_minutes);
                $this->assertSame(2700, app(PlanningWorkSelectionService::class)->select($owner, $task->id)['work_items'][0]['effort']['completed_credit_seconds']);
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

    public static function competingActions(): array
    {
        return [['complete', 'complete', '2026-10-19T09:30:00Z', 'completed'],
            ['complete', 'cancel', '2026-10-19T09:30:00Z', 'completed'],
            ['reschedule', 'reschedule', '2026-10-19T08:00:00.500000Z', 'created']];
    }

    public function test_task_deletion_and_rescheduling_contend_and_leave_no_live_reservation(): void
    {
        [$owner, $task, $source] = $this->fixture();
        $reminder = Reminder::factory()->for($owner)->create(['study_session_id' => $source->id]);
        $workers = [];
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            foreach (['delete', 'reschedule'] as $action) {
                $worker = $this->worker($owner, ['action' => $action, 'task_id' => $task->id, 'session_id' => $source->id]);
                $workers[] = $worker;
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
            DB::commit();
            $this->assertSame('deleted', $this->outcome($workers[0])['status']);
            $this->assertContains($this->outcome($workers[1])['status'], ['conflict', 'created']);
            $this->assertTrue($task->fresh()->trashed());
            $this->assertSame(0, StudySession::where('status', StudySessionStatus::Planned)->count());
            $this->assertLessThanOrEqual(1, StudySession::where('rescheduled_from_session_id', $source->id)->count());
            $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function test_stage_c_confirmation_waits_for_actual_cancellation_and_reads_the_committed_release(): void
    {
        [$owner, , $source] = $this->fixture();
        $preview = app(StudyPlanPersistenceService::class)->preview($owner, '2026-10-19', '2026-10-19');
        $worker = null;
        StudySession::updated(function () use ($owner, $preview, &$worker): void {
            $worker = $this->worker($owner, ['action' => 'confirm', 'preview_token' => $preview['preview_token']]);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
        });
        try {
            app(StudySessionLifecycleService::class)->cancel($owner, (string) $source->id);
            $this->assertSame('conflict', $this->outcome($worker)['status']);
            $this->assertDatabaseCount('study_sessions', 1);
            $this->assertSame(StudySessionStatus::Cancelled, $source->fresh()->status);
        } finally {
            StudySession::flushEventListeners();
            $worker?->stop();
        }
    }

    public function test_reschedule_waits_for_stage_c_then_observes_new_committed_blockers(): void
    {
        [$owner, , $source] = $this->fixture();
        $preview = app(StudyPlanPersistenceService::class)->preview($owner, '2026-10-19', '2026-10-19');
        $worker = null;
        StudySession::created(function () use ($owner, $source, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, ['action' => 'reschedule', 'session_id' => $source->id,
                    'starts_at' => '2026-10-19T08:30:00Z', 'ends_at' => '2026-10-19T09:30:00Z']);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        });
        try {
            $result = app(StudyPlanPersistenceService::class)->confirm($owner, $preview['preview_token']);
            $this->assertSame('conflict', $this->outcome($worker)['status']);
            $this->assertSame(StudySessionStatus::Planned, $source->fresh()->status);
            $this->assertDatabaseCount('study_sessions', 1 + $result['sessions']->count());
        } finally {
            StudySession::flushEventListeners();
            $worker?->stop();
        }
    }

    public function test_waiter_sees_rollback_after_source_status_was_written_and_can_transfer_once(): void
    {
        [$owner, , $source] = $this->fixture();
        $worker = null;
        StudySession::updated(function () use ($owner, $source, &$worker): void {
            $worker = $this->worker($owner, ['action' => 'reschedule', 'session_id' => $source->id]);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            throw new RuntimeException('Injected failure after predecessor write.');
        });
        try {
            try {
                app(StudySessionReschedulingService::class)->reschedule($owner, (string) $source->id, '2026-10-19T11:00:00Z', '2026-10-19T12:00:00Z');
                $this->fail('The injected failure did not roll back.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected failure after predecessor write.', $exception->getMessage());
            }
            $this->assertSame('created', $this->outcome($worker)['status']);
            $this->assertDatabaseCount('study_sessions', 2);
            $this->assertSame(1, StudySession::where('rescheduled_from_session_id', $source->id)->count());
        } finally {
            StudySession::flushEventListeners();
            $worker?->stop();
        }
    }

    public function test_reschedule_waits_for_actual_lesson_mutation_then_validates_committed_schedule(): void
    {
        [$owner, , $source] = $this->fixture();
        $lesson = Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-19 13:00:00', 'ends_at' => '2026-10-19 14:00:00']);
        $worker = null;
        Lesson::updated(function () use ($owner, $source, &$worker): void {
            $worker = $this->worker($owner, ['action' => 'reschedule', 'session_id' => $source->id]);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
        });
        try {
            app(LessonService::class)->update($owner, $lesson, ['starts_at' => '2026-10-19T11:00:00Z', 'ends_at' => '2026-10-19T12:00:00Z']);
            $this->assertSame('conflict', $this->outcome($worker)['status']);
            $this->assertSame(StudySessionStatus::Planned, $source->fresh()->status);
            $this->assertDatabaseCount('study_sessions', 1);
        } finally {
            Lesson::flushEventListeners();
            $worker?->stop();
        }
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 120]);
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00']);

        return [$owner, $task, $source];
    }

    private function worker(User $owner, array $parameters): Process
    {
        $config = DB::connection()->getConfig();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/study_session_mutation_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => 'student_planner_testing',
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => $config['unix_socket'] ?? '',
        ]);
        $worker->setInput(json_encode(['owner_id' => $owner->id, 'now' => '2026-10-19T08:00:00.500000Z', ...$parameters], JSON_THROW_ON_ERROR));
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
