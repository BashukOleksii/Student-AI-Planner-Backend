<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class AiLessonActionConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.openai_key' => 'fake-stage11-key', 'ai.model' => 'gpt-5.4-mini']);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->workers as [$process, $stream]) {
                $process->stop();
                $stream->close();
            }
            $this->truncateTablesForAllConnections();
        });
    }

    private function fixture(string $name = 'propose_lesson_create'): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $subject = Subject::factory()->for($owner)->create();
        $lesson = $name === 'propose_lesson_create' ? null : Lesson::factory()->for($owner)->create(['subject_id' => $subject->id]);
        $args = $lesson === null ? ['subject_id' => $subject->id, 'type' => 'lecture',
            'starts_at' => '2026-11-04T07:00:00Z', 'ends_at' => '2026-11-04T08:00:00Z']
            : ['lesson_id' => $lesson->id, 'room' => 'Reviewed room'];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall($name, json_encode($args))]))
            ->push($this->aiResponse([$this->aiText()]));
        $id = $this->actingAs($owner, 'web')->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Review a Lesson.'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data.action.id');

        return [$owner, $conversation, AiPendingAction::findOrFail($id), $lesson];
    }

    private function worker(User $owner, AiConversation $conversation, ?AiPendingAction $action, array $options = []): array
    {
        $stream = new InputStream;
        $config = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/ai_task_action_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => TestDatabase::name(),
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

    public function test_two_confirmations_share_one_lesson_insert_and_one_durable_receipt(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        [$first, $gate] = $this->worker($owner, $conversation, $action, ['gate_on' => 'lesson_written']);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversation, $action);
        $this->waiting($connection, 'ai_conversations');
        $this->assertDatabaseCount('lessons', 0);
        $gate->write("resume\n");
        $one = $this->outcome($first);
        $two = $this->outcome($second);
        $this->assertSame(200, $one['status']);
        $this->assertSame(200, $two['status']);
        $this->assertTrue($two['body']['replayed']);
        $this->assertSame($one['body']['data']['receipt'], $two['body']['data']['receipt']);
        $this->assertDatabaseCount('lessons', 1);
        $this->assertSame(1, $conversation->messages()->where('tool_calls->version', 2)->count());
    }

    public function test_confirmation_waiting_for_direct_edit_reads_current_state_after_owner_lock(): void
    {
        [$owner, $conversation, $action, $lesson] = $this->fixture('propose_lesson_update');
        [$first, $gate] = $this->worker($owner, $conversation, $action,
            ['operation' => 'lesson_update', 'lesson_id' => $lesson->id, 'gate_on' => 'lesson_written']);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversation, $action);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($first)['status']);
        $outcome = $this->outcome($second);
        $this->assertSame(409, $outcome['status']);
        $this->assertSame('action_stale', $outcome['body']['code']);
        $this->assertSame('Direct concurrent edit', $lesson->fresh()->room);
        $this->assertSame('pending', $action->fresh()->status);
    }

    public function test_expiry_during_lesson_write_rolls_back_insert_and_receipt(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        DB::table('ai_pending_actions')->where('id', $action->id)->update(['expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        [$worker, $gate] = $this->worker($owner, $conversation, $action, ['gate_on' => 'lesson_written']);
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
        $outcome = $this->outcome($worker);
        $this->assertSame(410, $outcome['status']);
        $this->assertSame('action_expired', $outcome['body']['code']);
        $this->assertDatabaseCount('lessons', 0);
        $this->assertNull($action->fresh()->receipt);
    }
}
