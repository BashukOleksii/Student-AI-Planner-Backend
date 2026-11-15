<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\Support\TestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('mysql')]
class CompactAiTaskActionConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => 'openai', 'ai.openai_key' => 'fake', 'ai.model' => 'gpt-5.4-mini']);
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
        $arguments = $task === null ? ['title' => 'Exactly once'] : ['task_id' => $task->id, ...$name === 'propose_task_update' ? ['title' => 'Confirmed edit'] : []];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall($name, json_encode($arguments))]))->push($this->aiResponse([$this->aiText()]));
        $this->app['auth']->forgetGuards();
        $id = $this->actingAs($owner, 'web')->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Propose one task action.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data.action.id');

        return [$owner, $conversation, AiPendingAction::findOrFail($id), $task];
    }

    private function worker(User $owner, AiConversation $conversation, ?AiPendingAction $action, array $options = []): array
    {
        $stream = new InputStream;
        $config = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/ai_task_action_worker.php')], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => TestDatabase::name(), 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => $config['unix_socket'] ?? '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'], $stream, 20);
        $stream->write(json_encode(['owner_id' => $owner->id, 'conversation_id' => $conversation->id, 'action_id' => $action?->id, 'operation' => 'confirm', ...$options], JSON_THROW_ON_ERROR)."\n");
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
            WHERE threads.PROCESSLIST_ID = ? AND locks.OBJECT_SCHEMA = DATABASE() AND locks.OBJECT_NAME = ?
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
        $this->assertDatabaseCount('tasks', 0);
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
        return [0 => [true]];
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
}
