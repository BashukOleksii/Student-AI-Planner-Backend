<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

class AiTaskActionConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.openai_key' => 'fake', 'ai.model' => 'gpt-5.4-mini']);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->workers as [$process, $stream]) {
                $process->stop();
                $stream->close();
            }
            $this->truncateTablesForAllConnections();
        });
    }

    private function fixture(string $name = 'propose_task_create', ?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $task = $name === 'propose_task_create' ? null : Task::factory()->for($owner)->create();
        $arguments = $task === null ? ['title' => 'Exactly once'] : ['task_id' => $task->id,
            ...($name === 'propose_task_update' ? ['title' => 'Confirmed edit'] : [])];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall($name, json_encode($arguments))]))
            ->push($this->aiResponse([$this->aiText()]));
        $this->app['auth']->forgetGuards();
        $id = $this->actingAs($owner, 'web')->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Propose one task action.'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data.action.id');

        return [$owner, $conversation, AiPendingAction::findOrFail($id), $task];
    }

    private function worker(User $owner, AiConversation $conversation, ?AiPendingAction $action, array $options = []): array
    {
        $stream = new InputStream;
        $config = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/ai_task_action_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => 'student_planner_testing',
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => $config['unix_socket'] ?? '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], $stream, 20);
        $stream->write(json_encode(['owner_id' => $owner->id, 'conversation_id' => $conversation->id, 'action_id' => $action?->id,
            'operation' => 'confirm', ...$options], JSON_THROW_ON_ERROR)."\n");
        $this->workers[] = [$process, $stream];
        $process->start();
        $ready = $this->event($process, 'ready');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $ready['connection_id']);

        return [$process, $stream, $ready['connection_id']];
    }

    private function event(Process $worker, string $key): array
    {
        $deadline = microtime(true) + 5;
        do {
            foreach (explode("\n", trim($worker->getOutput())) as $line) {
                $event = json_decode($line, true);
                if (isset($event[$key])) {
                    return $event;
                }
            }
            if (! $worker->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Missing worker event '.$key.': '.$worker->getErrorOutput().' '.$worker->getOutput());
    }

    private function outcome(Process $worker): array
    {
        $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
        foreach (array_reverse(explode("\n", trim($worker->getOutput()))) as $line) {
            $event = json_decode($line, true);
            if (isset($event['status'])) {
                return $event;
            }
        }
        throw new RuntimeException('Missing HTTP worker outcome.');
    }

    private function waiting(int $connection, string $table): void
    {
        $deadline = microtime(true) + 5;
        do {
            $wait = DB::selectOne(<<<'SQL'
                SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits AS waits
                JOIN performance_schema.threads AS threads ON waits.REQUESTING_THREAD_ID = threads.THREAD_ID
                JOIN performance_schema.data_locks AS locks ON waits.REQUESTING_ENGINE_LOCK_ID = locks.ENGINE_LOCK_ID
                WHERE threads.PROCESSLIST_ID = ? AND locks.OBJECT_SCHEMA = 'student_planner_testing' AND locks.OBJECT_NAME = ?
                SQL, [$connection, $table]);
            if ((int) $wait->total > 0) {
                $this->assertGreaterThan(0, (int) $wait->total);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('No real MySQL lock contention on '.$table);
    }

    public function test_two_simultaneous_create_confirmations_execute_once_and_share_the_durable_receipt(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        [$first, $gate] = $this->worker($owner, $conversation, $action, ['gate_on' => 'task_written']);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversation, $action);
        $this->waiting($connection, 'ai_conversations');
        $this->assertDatabaseCount('tasks', 0); // Independent connection cannot see the uncommitted insert.
        $gate->write("resume\n");
        $one = $this->outcome($first);
        $two = $this->outcome($second);
        $this->assertSame(200, $one['status']);
        $this->assertSame(200, $two['status']);
        $this->assertEquals($one['body']['data']['receipt'], $two['body']['data']['receipt']);
        $this->assertTrue($two['body']['replayed']);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame(1, $conversation->messages()->where('tool_calls->version', 2)->count());
    }

    public static function orderings(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('orderings')]
    public function test_confirmation_versus_rejection_serializes_one_terminal_outcome(bool $confirmFirst): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        [$first, $gate] = $this->worker($owner, $conversation, $action, ['operation' => $confirmFirst ? 'confirm' : 'reject', 'gate_on' => 'action_saved']);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversation, $action, ['operation' => $confirmFirst ? 'reject' : 'confirm']);
        $this->waiting($connection, 'ai_conversations');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($first)['status']);
        $this->assertSame(409, $this->outcome($second)['status']);
        $this->assertSame($confirmFirst ? 'executed' : 'rejected', $action->fresh()->status);
        $this->assertDatabaseCount('tasks', $confirmFirst ? 1 : 0);
    }

    public function test_confirmation_waiting_for_owner_rechecks_authoritative_expiration(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        DB::table('ai_pending_actions')->where('id', $action->id)->update(['expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            [$worker, , $connection] = $this->worker($owner, $conversation, $action);
            $this->waiting($connection, 'users');
            $deadline = microtime(true) + 3;
            do {
                $elapsed = DB::selectOne('SELECT expires_at <= UTC_TIMESTAMP(6) AS elapsed FROM ai_pending_actions WHERE id = ?', [$action->id])->elapsed;
                if ($elapsed) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue((bool) $elapsed);
            DB::commit();
            $result = $this->outcome($worker);
            $this->assertSame(410, $result['status']);
            $this->assertSame('expired', $action->fresh()->status);
            $this->assertDatabaseCount('tasks', 0);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
        }
    }

    public function test_expiry_during_a_slow_task_mutation_rolls_back_business_effect_and_receipt(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        DB::table('ai_pending_actions')->where('id', $action->id)->update(['expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        [$worker, $gate] = $this->worker($owner, $conversation, $action, ['gate_on' => 'task_written']);
        $this->event($worker, 'gate');
        $deadline = microtime(true) + 3;
        do {
            $elapsed = DB::selectOne('SELECT expires_at <= UTC_TIMESTAMP(6) AS elapsed FROM ai_pending_actions WHERE id = ?', [$action->id])->elapsed;
            if ($elapsed) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertTrue((bool) $elapsed);
        $gate->write("resume\n");
        $result = $this->outcome($worker);
        $this->assertSame(410, $result['status']);
        $this->assertSame('action_expired', $result['body']['code']);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertNull($action->fresh()->receipt);
        [$retry] = $this->worker($owner, $conversation, $action);
        $this->assertSame(410, $this->outcome($retry)['status']);
        $this->assertSame('expired', $action->fresh()->status);
    }

    #[DataProvider('orderings')]
    public function test_confirmation_versus_direct_rest_update_never_overwrites_an_unreviewed_change(bool $confirmFirst): void
    {
        [$owner, $conversation, $action, $task] = $this->fixture('propose_task_update');
        $firstOptions = ['operation' => $confirmFirst ? 'confirm' : 'task_update', 'task_id' => $task->id, 'gate_on' => 'task_written'];
        [$first, $gate] = $this->worker($owner, $conversation, $action, $firstOptions);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversation, $action, ['operation' => $confirmFirst ? 'task_update' : 'confirm', 'task_id' => $task->id]);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($first)['status']);
        $result = $this->outcome($second);
        $this->assertSame($confirmFirst ? 200 : 409, $result['status']);
        if (! $confirmFirst) {
            $this->assertSame('action_stale', $result['body']['code']);
        }
        $this->assertSame('Direct concurrent edit', $task->fresh()->title);
        $this->assertSame($confirmFirst ? 'executed' : 'pending', $action->fresh()->status);
    }

    #[DataProvider('orderings')]
    public function test_completion_confirmation_versus_task_lifecycle_uses_current_locked_status(bool $confirmFirst): void
    {
        [$owner, $conversation, $action, $task] = $this->fixture('propose_task_complete');
        [$first, $gate] = $this->worker($owner, $conversation, $action,
            ['operation' => $confirmFirst ? 'confirm' : 'task_complete', 'task_id' => $task->id, 'gate_on' => 'task_written']);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversation, $action,
            ['operation' => $confirmFirst ? 'task_reopen' : 'confirm', 'task_id' => $task->id]);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($first)['status']);
        $this->assertSame($confirmFirst ? 200 : 409, $this->outcome($second)['status']);
        $this->assertSame($confirmFirst ? 'pending' : 'completed', $task->fresh()->status->value);
        $this->assertSame($confirmFirst ? 'executed' : 'pending', $action->fresh()->status);
    }

    public function test_rejection_can_progress_during_an_unrelated_domain_owner_transaction(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            [$rejection] = $this->worker($owner, $conversation, $action, ['operation' => 'reject']);
            $this->assertSame(200, $this->outcome($rejection)['status']);
            $this->assertSame('rejected', $action->fresh()->status);
            $this->assertDatabaseCount('tasks', 0);
        } finally {
            DB::rollBack();
        }
    }

    public function test_owner_wait_does_not_hide_new_session_reminder_dependencies_in_an_old_mysql_snapshot(): void
    {
        [$owner, $conversation, $action, $task] = $this->fixture('propose_task_complete');
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            [$confirmation, , $connection] = $this->worker($owner, $conversation, $action);
            $this->waiting($connection, 'users');
            $session = StudySession::factory()->for($task)->create();
            $reminder = Reminder::factory()->for($owner)->create(['study_session_id' => $session->id]);
            $sessionBefore = $session->fresh()->getAttributes();
            DB::commit();
            $this->assertSame(200, $this->outcome($confirmation)['status']);
            $this->assertSame('cancelled', $reminder->fresh()->status->value);
            $this->assertSame($sessionBefore, $session->fresh()->getAttributes());
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
        }
    }

    public function test_expired_h2_worker_cannot_persist_its_prepared_action_or_release_a_successor_turn(): void
    {
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        [$first, $gate] = $this->worker($owner, $conversation, null,
            ['operation' => 'chat', 'request_id' => (string) Str::uuid(), 'hold_provider' => true]);
        $prepared = $this->event($first, 'prepared_action_id')['prepared_action_id'];
        $this->event($first, 'gate');
        $this->assertDatabaseCount('ai_pending_actions', 0);
        // Provider waiting holds no conversation lock, so another process may recover after expiry.
        DB::table('ai_conversation_turns')->update(['lease_expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        [$successor] = $this->worker($owner, $conversation, null, ['operation' => 'chat', 'request_id' => (string) Str::uuid()]);
        $this->assertSame(200, $this->outcome($successor)['status']);
        $gate->write("resume\n");
        $result = $this->outcome($first);
        $this->assertSame(409, $result['status']);
        $this->assertSame('turn_expired', $result['body']['code']);
        $this->assertDatabaseCount('ai_pending_actions', 1);
        $this->assertDatabaseMissing('ai_pending_actions', ['id' => $prepared]);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_failed_taskservice_write_rolls_back_receipt_before_waiting_confirmation_can_retry(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        [$first, $gate] = $this->worker($owner, $conversation, $action, ['gate_on' => 'task_written', 'fail_after_write' => true]);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversation, $action);
        $this->waiting($connection, 'ai_conversations');
        $this->assertSame('pending', $action->fresh()->status);
        $this->assertNull($action->fresh()->receipt);
        $this->assertDatabaseCount('tasks', 0);
        $gate->write("resume\n");
        $this->assertSame(500, $this->outcome($first)['status']);
        $this->assertSame(200, $this->outcome($second)['status']);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('executed', $action->fresh()->status);
    }

    public function test_lost_response_after_commit_can_be_retried_from_a_new_process_without_business_reexecution(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        [$first] = $this->worker($owner, $conversation, $action, ['gate_on' => 'response_committed']);
        $this->event($first, 'gate');
        $this->assertSame('executed', $action->fresh()->status);
        $receipt = $action->fresh()->receipt;
        $before = Task::sole()->getAttributes();
        $first->stop(0, SIGKILL);
        [$retry] = $this->worker($owner, $conversation, $action);
        $result = $this->outcome($retry);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['replayed']);
        $this->assertEquals($receipt, $result['body']['data']['receipt']);
        $this->assertSame($before, Task::sole()->getAttributes());
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_active_turn_blocks_decisions_in_its_conversation_but_other_conversations_and_owners_progress(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        [, $otherConversation, $otherAction] = $this->fixture(owner: $owner);
        [$foreignOwner, $foreignConversation, $foreignAction] = $this->fixture();
        [$chat, $gate] = $this->worker($owner, $conversation, null,
            ['operation' => 'chat', 'request_id' => (string) Str::uuid(), 'hold_provider' => true]);
        $this->event($chat, 'gate');
        foreach (['confirm', 'reject'] as $operation) {
            [$worker] = $this->worker($owner, $conversation, $action, ['operation' => $operation]);
            $result = $this->outcome($worker);
            $this->assertSame(409, $result['status']);
            $this->assertSame('conversation_busy', $result['body']['code']);
        }
        $otherOwner = $otherConversation->user;
        [$independent] = $this->worker($otherOwner, $otherConversation, $otherAction);
        $this->assertSame(200, $this->outcome($independent)['status']);
        [$differentOwner] = $this->worker($foreignOwner, $foreignConversation, $foreignAction);
        $this->assertSame(200, $this->outcome($differentOwner)['status']);
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($chat)['status']);
        [$confirmation] = $this->worker($owner, $conversation, $action);
        $this->assertSame(200, $this->outcome($confirmation)['status']);
    }
}
