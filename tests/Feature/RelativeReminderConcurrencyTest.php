<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Exceptions\ReminderIntegrityException;
use App\Models\Reminder;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderRules;
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

class RelativeReminderConcurrencyTest extends TestCase
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

    #[DataProvider('unitsAndDirections')]
    public function test_deadline_and_offset_edit_observe_current_committed_values(bool $childUnit, bool $targetFirst): void
    {
        [$owner, $task, $child, $reminder] = $this->fixture($childUnit);
        $worker = null;
        $targetClass = $childUnit ? Subtask::class : Task::class;
        $parameters = $targetFirst ? ['action' => 'update', 'reminder_id' => $reminder->id, 'data' => ['offset_minutes' => 30]]
            : ['action' => $childUnit ? 'child_deadline' : 'task_deadline', 'task_id' => $task->id, 'subtask_id' => $child->id, 'data' => ['deadline_at' => '2026-10-22T12:00:00Z']];
        ($targetFirst ? $targetClass : Reminder::class)::updated(function () use ($owner, $parameters, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, $parameters);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        });
        try {
            if ($targetFirst) {
                $this->changeDeadline($owner, $task, $childUnit ? $child : null);
            } else {
                app(ReminderService::class)->update($owner, (string) $reminder->id, ['offset_minutes' => 30]);
            }
            $this->assertSame($targetFirst ? 'updated' : 'changed', $this->outcome($worker)['status']);
            $this->assertSame(30, $reminder->fresh()->offset_minutes);
            $this->assertSame('2026-10-22 12:30:00', $reminder->fresh()->getRawOriginal('trigger_at'));
            $this->assertSame(ReminderStatus::Scheduled, $reminder->fresh()->status);
        } finally {
            $targetClass::flushEventListeners();
            Reminder::flushEventListeners();
            $worker?->stop();
        }
    }

    public static function unitsAndDirections(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('units')]
    public function test_competing_deadline_and_offset_writers_keep_latest_pair_consistent(bool $childUnit): void
    {
        [$owner, $task, $child, $reminder] = $this->fixture($childUnit);
        $workers = [];
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ([['action' => $childUnit ? 'child_deadline' : 'task_deadline', 'task_id' => $task->id, 'subtask_id' => $child->id, 'data' => ['deadline_at' => '2026-10-22T12:00:00Z']],
                ['action' => 'update', 'reminder_id' => $reminder->id, 'data' => ['offset_minutes' => 30]]] as $parameters) {
                $workers[] = $worker = $this->worker($owner, $parameters);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
            DB::commit();
            $this->assertSame('changed', $this->outcome($workers[0])['status']);
            $this->assertSame('updated', $this->outcome($workers[1])['status']);
            $this->assertSame('2026-10-22 12:30:00', $reminder->fresh()->getRawOriginal('trigger_at'));
            $this->assertSame(30, $reminder->fresh()->offset_minutes);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public static function units(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('unitsAndDirections')]
    public function test_deadline_change_and_cancellation_preserve_last_scheduled_history(bool $childUnit, bool $targetFirst): void
    {
        [$owner, $task, $child, $reminder] = $this->fixture($childUnit);
        $oldTrigger = $reminder->getRawOriginal('trigger_at');
        $worker = null;
        $class = $childUnit ? Subtask::class : Task::class;
        ($targetFirst ? $class : Reminder::class)::updated(function () use ($owner, $task, $child, $reminder, $childUnit, $targetFirst, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, $targetFirst ? ['action' => 'cancel', 'reminder_id' => $reminder->id]
                    : ['action' => $childUnit ? 'child_deadline' : 'task_deadline', 'task_id' => $task->id, 'subtask_id' => $child->id, 'data' => ['deadline_at' => '2026-10-22T12:00:00Z']]);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        });
        try {
            if ($targetFirst) {
                $this->changeDeadline($owner, $task, $childUnit ? $child : null);
            } else {
                app(ReminderService::class)->cancel($owner, (string) $reminder->id);
            }
            $this->assertSame($targetFirst ? 'cancelled' : 'changed', $this->outcome($worker)['status']);
            $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
            $this->assertSame($targetFirst ? '2026-10-22 11:45:00' : $oldTrigger, $reminder->fresh()->getRawOriginal('trigger_at'));
        } finally {
            $class::flushEventListeners();
            Reminder::flushEventListeners();
            $worker?->stop();
        }
    }

    #[DataProvider('retirementOrderings')]
    public function test_relative_creation_serializes_with_actual_completion_or_deletion(bool $childUnit, bool $targetFirst, string $operation): void
    {
        [$owner, $task, $child] = $this->fixture($childUnit);
        Reminder::query()->delete(); // Remove only the test's initial fixture record before contention.
        $field = $childUnit ? 'subtask_id' : 'task_id';
        $targetId = $childUnit ? $child->id : $task->id;
        $worker = null;
        $class = $childUnit ? Subtask::class : Task::class;
        $listener = function () use ($owner, $task, $child, $field, $targetId, $childUnit, $targetFirst, $operation, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, $targetFirst ? ['action' => 'create', 'data' => $this->body($field, $targetId)]
                    : ['action' => ($childUnit ? 'child_' : 'task_').$operation, 'task_id' => $task->id, 'subtask_id' => $child->id]);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        };
        if ($targetFirst) {
            $operation === 'delete' ? $class::deleted($listener) : $class::updated($listener);
        } else {
            Reminder::created($listener);
        }
        try {
            if (! $targetFirst) {
                $reminder = app(ReminderService::class)->create($owner, $this->body($field, $targetId));
            } elseif ($childUnit) {
                app(SubtaskService::class)->$operation($owner, $task, $child);
            } else {
                app(TaskService::class)->$operation($owner, $task);
            }
            $this->assertSame($targetFirst ? 'invalid' : ($operation === 'complete' ? 'completed' : 'deleted'), $this->outcome($worker)['status']);
            if ($targetFirst) {
                $this->assertDatabaseCount('reminders', 0);
            } else {
                $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
                $this->assertSame($targetId, $reminder->fresh()->getAttribute($field));
                $this->assertDatabaseCount('reminders', 1);
            }
        } finally {
            $class::flushEventListeners();
            Reminder::flushEventListeners();
            $worker?->stop();
        }
    }

    public static function retirementOrderings(): array
    {
        $cases = [];
        foreach (self::unitsAndDirections() as $pair) {
            foreach (['complete', 'delete'] as $operation) {
                $cases[] = [...$pair, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('units')]
    public function test_failed_deadline_batch_rolls_back_work_and_waiter_uses_old_committed_anchor(bool $childUnit): void
    {
        [$owner, $task, $child, $reminder] = $this->fixture($childUnit);
        $field = $childUnit ? 'subtask_id' : 'task_id';
        $other = app(ReminderService::class)->create($owner, $this->body($field, $childUnit ? $child->id : $task->id));
        $oldDeadline = ($childUnit ? $child : $task)->getRawOriginal('deadline_at');
        $oldOther = $other->fresh()->getAttributes();
        $worker = null;
        $writes = 0;
        Reminder::updated(function () use ($owner, $reminder, &$worker, &$writes): void {
            if (++$writes === 2) {
                $worker = $this->worker($owner, ['action' => 'update', 'reminder_id' => $reminder->id, 'data' => ['offset_minutes' => 30]]);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
                throw new RuntimeException('Injected second synchronization write failure.');
            }
        });
        try {
            try {
                $this->changeDeadline($owner, $task, $childUnit ? $child : null);
                $this->fail('Synchronization should have failed.');
            } catch (ReminderIntegrityException) {
                $this->assertSame('updated', $this->outcome($worker)['status']);
            }
            $this->assertSame($oldDeadline, ($childUnit ? $child : $task)->fresh()->getRawOriginal('deadline_at'));
            $this->assertSame($oldOther, $other->fresh()->getAttributes());
            $expected = app(ReminderRules::class)->databaseTime(app(ReminderRules::class)->resolve(app(ReminderRules::class)->wireTime($oldDeadline), 30));
            $this->assertSame($expected, $reminder->fresh()->getRawOriginal('trigger_at'));
        } finally {
            Reminder::flushEventListeners();
            $worker?->stop();
        }
    }

    public function test_failed_offset_write_is_rolled_back_before_waiting_deadline_update(): void
    {
        [$owner, $task, $child, $reminder] = $this->fixture(false);
        $worker = null;
        Reminder::updated(function () use ($owner, $task, &$worker): void {
            $worker = $this->worker($owner, ['action' => 'task_deadline', 'task_id' => $task->id, 'data' => ['deadline_at' => '2026-10-22T12:00:00Z']]);
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            throw new RuntimeException('Injected offset write failure.');
        });
        try {
            try {
                app(ReminderService::class)->update($owner, (string) $reminder->id, ['offset_minutes' => 30]);
                $this->fail('The injected failure was ignored.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected offset write failure.', $exception->getMessage());
            }
            $this->assertSame('changed', $this->outcome($worker)['status']);
            $this->assertSame(-15, $reminder->fresh()->offset_minutes);
            $this->assertSame('2026-10-22 11:45:00', $reminder->fresh()->getRawOriginal('trigger_at'));
        } finally {
            Reminder::flushEventListeners();
            $worker?->stop();
        }
    }

    public function test_elapsed_recalculation_after_owner_wait_cancels_instead_of_blocking_deadline_change(): void
    {
        [$owner, $task, , $reminder] = $this->fixture(false);
        $oldTrigger = $reminder->getRawOriginal('trigger_at');
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        $worker = $this->worker($owner, ['action' => 'task_deadline', 'task_id' => $task->id,
            'data' => ['deadline_at' => '2026-10-19T08:15:01Z'], 'now_after_lock' => '2026-10-19T08:00:01.000001Z']);
        try {
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            DB::commit();
            $this->assertSame('changed', $this->outcome($worker)['status']);
            $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
            $this->assertSame($oldTrigger, $reminder->fresh()->getRawOriginal('trigger_at'));
            $this->assertSame('2026-10-19 08:15:01', $task->fresh()->getRawOriginal('deadline_at'));
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    public function test_foreign_target_mutation_cannot_bypass_owned_reload_after_contention(): void
    {
        [$owner, $task, , $reminder] = $this->fixture(false);
        $foreign = User::factory()->create();
        $before = [$task->fresh()->getAttributes(), $reminder->fresh()->getAttributes()];
        DB::beginTransaction();
        User::whereKey($foreign->id)->lockForUpdate()->firstOrFail();
        $worker = $this->worker($foreign, ['action' => 'task_deadline', 'task_id' => $task->id, 'data' => ['deadline_at' => null]]);
        try {
            $this->assertWaitingOnOwner($this->readyConnection($worker));
            DB::commit();
            $this->assertSame('not_found', $this->outcome($worker)['status']);
            $this->assertSame($before, [$task->fresh()->getAttributes(), $reminder->fresh()->getAttributes()]);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    private function fixture(bool $childUnit): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 120, 'deadline_at' => '2026-10-21 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['deadline_at' => null, 'estimated_minutes' => 120]);
        if ($childUnit) {
            $task->update(['deadline_at' => '2026-10-25 12:00:00']);
            $child->update(['deadline_at' => '2026-10-20 12:00:00']);
        }
        $field = $childUnit ? 'subtask_id' : 'task_id';
        $reminder = app(ReminderService::class)->create($owner, $this->body($field, $childUnit ? $child->id : $task->id));

        return [$owner, $task, $child, $reminder];
    }

    private function body(string $field, int $id): array
    {
        return [$field => $id, 'anchor' => app(ReminderRules::class)->anchorFor($field)->value, 'offset_minutes' => -15];
    }

    private function changeDeadline(User $owner, Task $task, ?Subtask $child): void
    {
        $data = ['deadline_at' => '2026-10-22T12:00:00Z'];
        if ($child === null) {
            app(TaskService::class)->update($owner, $task, $data);
        } else {
            app(SubtaskService::class)->update($owner, $task, $child, $data);
        }
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
