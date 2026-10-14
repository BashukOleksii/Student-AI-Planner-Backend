<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Exceptions\ReminderIntegrityException;
use App\Models\Lesson;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifications\NotificationInboxService;
use App\Services\Planning\StudyReplanningPersistenceService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReschedulingService;
use App\Services\Reminders\ReminderDeliveryService;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReminderDeliveryConcurrencyTest extends TestCase
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

    public function test_two_actual_dispatchers_commit_exactly_one_notification(): void
    {
        [$owner, , , , $reminder] = $this->fixture('general');
        $workers = [];
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            foreach (range(1, 2) as $unused) {
                $workers[] = $worker = $this->worker($owner, ['action' => 'dispatch', 'reminder_id' => $reminder->id]);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
            DB::commit();
            $results = array_map(fn ($worker) => $this->outcome($worker)['status'], $workers);
            sort($results);
            $this->assertSame(['sent', 'skipped'], $results);
            $this->assertSame(ReminderStatus::Sent, $reminder->fresh()->status);
            $this->assertDatabaseCount('notifications', 1);
            $this->assertSame($reminder->id, $owner->notifications()->sole()->reminder_id);
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
    public function test_dispatch_and_actual_lifecycle_mutation_serialize_in_both_orders(string $action, bool $deliveryFirst): void
    {
        $kind = $action === 'task_deadline' || $action === 'offset_edit' ? 'task' : (in_array($action, ['cancel', 'trigger_edit'], true) ? 'general' : 'session');
        [$owner, $task, $child, $session, $reminder] = $this->fixture($kind);
        $preview = null;
        if ($action === 'replan') {
            Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
            $preview = app(StudyReplanningPersistenceService::class)->preview($owner, '2026-10-19', '2026-10-19');
        }
        $worker = null;
        $parameters = $this->parameters($action, $task, $child, $session, $reminder, $preview);
        $spawn = function () use ($owner, $parameters, $deliveryFirst, $reminder, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, $deliveryFirst ? $parameters : ['action' => 'dispatch', 'reminder_id' => $reminder->id]);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        };
        $model = match ($action) {
            'task_deadline', 'task_complete', 'task_delete' => Task::class,
            'child_complete', 'child_delete' => Subtask::class, 'session_cancel', 'session_reschedule', 'replan' => StudySession::class,
            default => Reminder::class
        };
        if ($deliveryFirst) {
            DatabaseNotification::created($spawn);
        } elseif (str_ends_with($action, 'delete')) {
            $model::deleted($spawn);
        } else {
            $model::updated($spawn);
        }
        try {
            if ($deliveryFirst) {
                $this->assertSame(['status' => 'sent'], app(ReminderDeliveryService::class)->process($owner->id, $reminder->id));
                $notification = $owner->notifications()->sole();
                $data = $notification->getAttributes();
                $expected = match ($action) {
                    'cancel', 'trigger_edit', 'offset_edit' => 'conflict', 'task_deadline' => 'changed',
                    'task_complete', 'child_complete' => 'completed', 'task_delete', 'child_delete' => 'deleted',
                    'session_cancel' => 'cancelled', 'session_reschedule' => 'rescheduled', 'replan' => 'applied'
                };
                $this->assertSame($expected, $this->outcome($worker)['status']);
                $this->assertSame(ReminderStatus::Sent, $reminder->fresh()->status);
                $this->assertSame($data, $notification->fresh()->getAttributes());
                $this->assertDatabaseCount('notifications', 1);
                $this->assertSame($reminder->getRawOriginal('trigger_at'), $reminder->fresh()->getRawOriginal('trigger_at'));
                if ($action === 'replan' || $action === 'session_reschedule') {
                    $this->assertSame($session->id, $notification->data['study_session_id']);
                    $this->assertSame(0, DatabaseNotification::where('reminder_id', '!=', $reminder->id)->count());
                }
            } else {
                $this->apply($action, $owner, $task, $child, $session, $reminder, $preview);
                $this->assertSame('skipped', $this->outcome($worker)['status']);
                $this->assertDatabaseCount('notifications', 0);
                $this->assertSame(in_array($action, ['task_deadline', 'trigger_edit', 'offset_edit'], true) ? ReminderStatus::Scheduled : ReminderStatus::Cancelled, $reminder->fresh()->status);
            }
        } finally {
            $model::flushEventListeners();
            DatabaseNotification::flushEventListeners();
            $worker?->stop();
        }
    }

    public static function mutations(): array
    {
        $cases = [];
        foreach (['cancel', 'trigger_edit', 'offset_edit', 'task_deadline', 'task_complete', 'child_complete', 'task_delete', 'child_delete', 'session_cancel', 'session_reschedule', 'replan'] as $action) {
            foreach ([false, true] as $order) {
                $cases[] = [$action, $order];
            }
        }

        return $cases;
    }

    #[DataProvider('rollbackLocations')]
    public function test_waiting_dispatcher_sees_rollback_after_notification_or_sent_write_and_retries_once(string $location): void
    {
        [$owner, , , , $reminder] = $this->fixture('general');
        $worker = null;
        $spawn = function () use ($owner, $reminder, &$worker): void {
            $worker = $this->worker($owner, ['action' => 'dispatch', 'reminder_id' => $reminder->id]);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            throw new RuntimeException('Injected delivery rollback.');
        };
        $location === 'notification' ? DatabaseNotification::created($spawn) : Reminder::updated($spawn);
        try {
            try {
                app(ReminderDeliveryService::class)->process($owner->id, $reminder->id);
                $this->fail('Injected failure was ignored.');
            } catch (ReminderIntegrityException) {
                $this->assertSame('sent', $this->outcome($worker)['status']);
            }
            $this->assertDatabaseCount('notifications', 1);
            $this->assertSame(ReminderStatus::Sent, $reminder->fresh()->status);
            $this->assertSame(['status' => 'skipped'], app(ReminderDeliveryService::class)->process($owner->id, $reminder->id));
        } finally {
            DatabaseNotification::flushEventListeners();
            Reminder::flushEventListeners();
            $worker?->stop();
        }
    }

    public static function rollbackLocations(): array
    {
        return [['notification'], ['sent']];
    }

    public function test_waiting_dispatcher_observes_rolled_back_deadline_and_original_due_trigger(): void
    {
        [$owner, $task, , , $reminder] = $this->fixture('task');
        $deadline = $task->getRawOriginal('deadline_at');
        $worker = null;
        Reminder::updated(function () use ($owner, $reminder, &$worker): void {
            $worker = $this->worker($owner, ['action' => 'dispatch', 'reminder_id' => $reminder->id]);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            throw new RuntimeException('Injected deadline synchronization failure.');
        });
        try {
            try {
                app(TaskService::class)->update($owner, $task, ['deadline_at' => '2026-10-22T12:00:00Z']);
                $this->fail('The injected failure was ignored.');
            } catch (ReminderIntegrityException) {
                $this->assertSame('sent', $this->outcome($worker)['status']);
            }
            $this->assertSame($deadline, $task->fresh()->getRawOriginal('deadline_at'));
            $this->assertSame('2026-10-19 08:00:00', $reminder->fresh()->getRawOriginal('trigger_at'));
            $this->assertDatabaseCount('notifications', 1);
        } finally {
            Reminder::flushEventListeners();
            $worker?->stop();
        }
    }

    public function test_delivery_timestamp_is_captured_after_actual_owner_contention(): void
    {
        [$owner, , , , $reminder] = $this->fixture('general');
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        $worker = $this->worker($owner, ['action' => 'dispatch', 'reminder_id' => $reminder->id, 'now_after_lock' => '2026-10-19T08:05:00.654321Z']);
        try {
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            DB::commit();
            $this->assertSame('sent', $this->outcome($worker)['status']);
            $this->assertSame('2026-10-19 08:05:00', $reminder->fresh()->getRawOriginal('sent_at'));
            $this->assertSame('2026-10-19 08:05:00', $owner->notifications()->sole()->getRawOriginal('created_at'));
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    public function test_independent_owner_can_commit_while_first_owner_delivery_is_uncommitted(): void
    {
        [$ownerA, , , , $sourceA] = $this->fixture('general');
        [$ownerB, , , , $sourceB] = $this->fixture('general');
        $worker = null;
        DatabaseNotification::created(function ($notification) use ($ownerA, $ownerB, $sourceB, &$worker): void {
            if ($notification->notifiable_id === $ownerA->id) {
                $worker = $this->worker($ownerB, ['action' => 'dispatch', 'reminder_id' => $sourceB->id]);
                $this->readyConnection($worker);
                // Parent still holds owner A and its uncommitted notification. B must finish now.
                $this->assertSame('sent', $this->outcome($worker)['status']);
            }
        });
        try {
            $this->assertSame(['status' => 'sent'], app(ReminderDeliveryService::class)->process($ownerA->id, $sourceA->id));
            $this->assertSame(ReminderStatus::Sent, $sourceA->fresh()->status);
            $this->assertSame(ReminderStatus::Sent, $sourceB->fresh()->status);
            $this->assertDatabaseCount('notifications', 2);
        } finally {
            DatabaseNotification::flushEventListeners();
            $worker?->stop();
        }
    }

    public function test_competing_read_requests_keep_first_read_timestamp_and_content(): void
    {
        [$owner, , , , $reminder] = $this->fixture('general');
        app(ReminderDeliveryService::class)->process($owner->id, $reminder->id);
        $notification = $owner->notifications()->sole();
        $data = $notification->data;
        $worker = null;
        DatabaseNotification::updated(function () use ($owner, $notification, &$worker): void {
            $worker = $this->worker($owner, ['action' => 'read', 'notification_id' => $notification->id, 'now' => '2026-10-19T10:00:00Z']);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
        });
        try {
            app(NotificationInboxService::class)->read($owner, $notification->id);
            $outcome = $this->outcome($worker);
            $this->assertSame('read', $outcome['status']);
            $this->assertSame('2026-10-19 08:00:00', $outcome['read_at']);
            $this->assertSame($data, $notification->fresh()->data);
            $this->assertSame(ReminderStatus::Sent, $reminder->fresh()->status);
            $this->assertDatabaseCount('notifications', 1);
        } finally {
            DatabaseNotification::flushEventListeners();
            $worker?->stop();
        }
    }

    private function fixture(string $kind): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 120, 'deadline_at' => '2026-10-21 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120, 'deadline_at' => '2026-10-20 12:00:00']);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);
        $attributes = match ($kind) {
            'task' => ['task_id' => $task->id, 'anchor' => 'task_deadline', 'offset_minutes' => -3120],
            'session' => ['study_session_id' => $session->id, 'anchor' => 'session_start', 'offset_minutes' => -60],
            default => [],
        };
        $reminder = Reminder::factory()->for($owner)->create([...$attributes, 'message' => 'Due', 'trigger_at' => '2026-10-19 08:00:00']);

        return [$owner, $task, $child, $session, $reminder];
    }

    private function parameters(string $action, Task $task, Subtask $child, StudySession $session, Reminder $reminder, ?array $preview): array
    {
        return match ($action) {
            'trigger_edit' => ['action' => 'update', 'reminder_id' => $reminder->id, 'data' => ['trigger_at' => '2026-10-20T08:00:00Z']],
            'offset_edit' => ['action' => 'update', 'reminder_id' => $reminder->id, 'data' => ['offset_minutes' => -3100]],
            'task_deadline' => ['action' => $action, 'task_id' => $task->id, 'data' => ['deadline_at' => '2026-10-22T12:00:00Z']],
            'replan' => ['action' => $action, 'preview_token' => $preview['preview_token']],
            default => ['action' => $action, 'task_id' => $task->id, 'subtask_id' => $child->id, 'session_id' => $session->id, 'reminder_id' => $reminder->id],
        };
    }

    private function apply(string $action, User $owner, Task $task, Subtask $child, StudySession $session, Reminder $reminder, ?array $preview): void
    {
        match ($action) {
            'cancel' => app(ReminderService::class)->cancel($owner, (string) $reminder->id),
            'trigger_edit' => app(ReminderService::class)->update($owner, (string) $reminder->id, ['trigger_at' => '2026-10-20T08:00:00Z']),
            'offset_edit' => app(ReminderService::class)->update($owner, (string) $reminder->id, ['offset_minutes' => -3100]),
            'task_deadline' => app(TaskService::class)->update($owner, $task, ['deadline_at' => '2026-10-22T12:00:00Z']),
            'task_complete' => app(TaskService::class)->complete($owner, $task),
            'child_complete' => app(SubtaskService::class)->complete($owner, $task, $child),
            'task_delete' => app(TaskService::class)->delete($owner, $task),
            'child_delete' => app(SubtaskService::class)->delete($owner, $task, $child),
            'session_cancel' => app(StudySessionLifecycleService::class)->cancel($owner, (string) $session->id),
            'session_reschedule' => app(StudySessionReschedulingService::class)->reschedule($owner, (string) $session->id, '2026-10-19T11:00:00Z', '2026-10-19T12:00:00Z'),
            'replan' => app(StudyReplanningPersistenceService::class)->confirm($owner, $preview['preview_token'], true, true),
        };
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
