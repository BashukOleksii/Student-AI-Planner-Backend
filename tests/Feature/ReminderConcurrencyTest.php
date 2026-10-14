<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReschedulingService;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReminderConcurrencyTest extends TestCase
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
    public function test_real_competing_reminder_writers(string $first, string $second): void
    {
        [$owner] = $this->fixture();
        $reminder = app(ReminderService::class)->create($owner, $this->body());
        $workers = [];
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ([$first, $second] as $index => $action) {
                $workers[] = $worker = $this->worker($owner, ['action' => $action, 'reminder_id' => $reminder->id,
                    'data' => $index === 0 ? ['message' => 'Committed update'] : ['trigger_at' => '2026-10-22T08:00:00Z']]);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
            DB::commit();
            $outcomes = array_map(fn ($worker) => $this->outcome($worker)['status'], $workers);
            if ($first === 'update' && $second === 'update') {
                $this->assertSame(['updated', 'updated'], $outcomes);
                $this->assertSame('Committed update', $reminder->fresh()->message);
                $this->assertSame('2026-10-22 08:00:00', $reminder->fresh()->getRawOriginal('trigger_at'));
                $this->assertSame(ReminderStatus::Scheduled, $reminder->fresh()->status);
            } elseif ($first === 'cancel' && $second === 'cancel') {
                sort($outcomes);
                $this->assertSame(['cancelled', 'conflict'], $outcomes);
                $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
            } else {
                $this->assertSame('cancelled', $outcomes[1]);
                $this->assertContains($outcomes[0], ['updated', 'conflict']);
                $this->assertSame($outcomes[0] === 'updated' ? 'Committed update' : 'Review notes', $reminder->fresh()->message);
                $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
            }
            $this->assertDatabaseCount('reminders', 1);
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
        return [['update', 'cancel'], ['update', 'update'], ['cancel', 'cancel']];
    }

    #[DataProvider('targetActions')]
    public function test_creation_waits_for_actual_target_transition_and_rejects_current_state(string $action, string $field): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $id = match ($field) {
            'task_id' => $task->id, 'subtask_id' => $child->id, default => $session->id
        };
        $worker = null;
        $listener = function () use ($owner, $field, $id, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, ['action' => 'create', 'data' => [...$this->body(), $field => $id]]);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        };
        $model = match ($action) {
            'task_delete' => Task::class, 'child_delete' => Subtask::class, default => StudySession::class
        };
        if (str_ends_with($action, 'delete')) {
            $model::deleted($listener);
        } else {
            $model::updated($listener);
        }
        try {
            match ($action) {
                'task_delete' => app(TaskService::class)->delete($owner, $task),
                'child_delete' => app(SubtaskService::class)->delete($owner, $task, $child),
                'session_cancel' => app(StudySessionLifecycleService::class)->cancel($owner, (string) $session->id),
                'session_reschedule' => app(StudySessionReschedulingService::class)->reschedule($owner, (string) $session->id, '2026-10-19T11:00:00Z', '2026-10-19T12:00:00Z'),
            };
            $this->assertSame('invalid', $this->outcome($worker)['status']);
            $this->assertDatabaseCount('reminders', 0);
        } finally {
            $model::flushEventListeners();
            $worker?->stop();
        }
    }

    #[DataProvider('targetActions')]
    public function test_target_writer_waits_for_creation_and_cleans_committed_reminder(string $action, string $field): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $id = match ($field) {
            'task_id' => $task->id, 'subtask_id' => $child->id, default => $session->id
        };
        $worker = null;
        Reminder::created(function () use ($owner, $task, $child, $session, $action, &$worker): void {
            $worker = $this->worker($owner, ['action' => $action, 'task_id' => $task->id, 'subtask_id' => $child->id, 'session_id' => $session->id]);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
        });
        try {
            $reminder = app(ReminderService::class)->create($owner, [...$this->body(), $field => $id]);
            $this->assertSame(match ($action) {
                'task_delete', 'child_delete' => 'deleted', 'session_cancel' => 'cancelled', default => 'rescheduled'
            }, $this->outcome($worker)['status']);
            $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
            $this->assertSame($id, $reminder->fresh()->getAttribute($field));
            $this->assertSame('Review notes', $reminder->fresh()->message);
            $this->assertDatabaseCount('reminders', 1);
        } finally {
            Reminder::flushEventListeners();
            $worker?->stop();
        }
    }

    public static function targetActions(): array
    {
        return [['task_delete', 'task_id'], ['child_delete', 'subtask_id'], ['session_cancel', 'study_session_id'], ['session_reschedule', 'study_session_id']];
    }

    #[DataProvider('commitOrRollback')]
    public function test_update_observes_actual_committed_or_rolled_back_cancellation(bool $rollback): void
    {
        [$owner] = $this->fixture();
        $reminder = app(ReminderService::class)->create($owner, $this->body());
        $worker = null;
        Reminder::updated(function () use ($owner, $reminder, $rollback, &$worker): void {
            $worker = $this->worker($owner, ['action' => 'update', 'reminder_id' => $reminder->id, 'data' => ['message' => 'After wait']]);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            if ($rollback) {
                throw new RuntimeException('Injected cancellation failure.');
            }
        });
        try {
            try {
                app(ReminderService::class)->cancel($owner, (string) $reminder->id);
                $this->assertFalse($rollback);
            } catch (RuntimeException $exception) {
                $this->assertTrue($rollback);
                $this->assertSame('Injected cancellation failure.', $exception->getMessage());
            }
            $this->assertSame($rollback ? 'updated' : 'conflict', $this->outcome($worker)['status']);
            $this->assertSame($rollback ? ReminderStatus::Scheduled : ReminderStatus::Cancelled, $reminder->fresh()->status);
            $this->assertSame($rollback ? 'After wait' : 'Review notes', $reminder->fresh()->message);
        } finally {
            Reminder::flushEventListeners();
            $worker?->stop();
        }
    }

    public static function commitOrRollback(): array
    {
        return [[false], [true]];
    }

    public function test_trigger_clock_is_rechecked_after_real_owner_wait(): void
    {
        [$owner] = $this->fixture();
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        $worker = $this->worker($owner, ['action' => 'create', 'data' => [...$this->body(), 'trigger_at' => '2026-10-19T08:00:01Z'], 'now_after_lock' => '2026-10-19T08:00:01.000001Z']);
        try {
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            DB::commit();
            $this->assertSame('invalid', $this->outcome($worker)['status']);
            $this->assertDatabaseCount('reminders', 0);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    public function test_foreign_owner_cannot_mutate_another_owners_reminder(): void
    {
        [$owner] = $this->fixture();
        $foreign = User::factory()->create();
        $reminder = app(ReminderService::class)->create($owner, $this->body());
        DB::beginTransaction();
        User::whereKey($foreign->id)->lockForUpdate()->firstOrFail();
        $worker = $this->worker($foreign, ['action' => 'cancel', 'reminder_id' => $reminder->id]);
        try {
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            DB::commit();
            $this->assertSame('not_found', $this->outcome($worker)['status']);
            $this->assertSame(ReminderStatus::Scheduled, $reminder->fresh()->status);
            $this->assertSame($owner->id, $reminder->fresh()->user_id);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    private function body(): array
    {
        return ['message' => 'Review notes', 'trigger_at' => '2026-10-20T07:45:00Z'];
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 120]);
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00']);

        return [$owner, $task, $child, $session];
    }

    private function worker(User $owner, array $parameters): Process
    {
        $config = DB::connection()->getConfig();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/reminder_mutation_worker.php')], base_path(), [
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
