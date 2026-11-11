<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\StudyAvailabilityWindow;
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

#[Group('mysql')]
#[Group('ai')]
#[Group('concurrency')]
class AiAvailabilityActionConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => 'openai', 'ai.openai_key' => 'fake-availability-key', 'ai.model' => 'gpt-5.4-mini']);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->workers as [$process, $stream]) {
                $process->stop();
                $stream->close();
            }
            $this->truncateTablesForAllConnections();
        });
    }

    private function fixture(string $name = 'create', ?User $owner = null, ?StudyAvailabilityWindow $window = null): array
    {
        $owner ??= User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $arguments = ['day_of_week' => 1, 'starts_at' => '12:00:00', 'ends_at' => '13:00:00'];
        if ($name === 'update') {
            $window ??= StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']);
            $arguments = ['availability_window_id' => $window->id, 'starts_at' => '12:00:00', 'ends_at' => '13:00:00'];
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall('propose_availability_'.$name, json_encode($arguments))]))
            ->push($this->aiResponse([$this->aiText()]));
        $id = $this->actingAs($owner, 'web')->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Review one availability change.'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data.action.id');

        return [$owner, $conversation, AiPendingAction::findOrFail($id), $window];
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

    public static function competingActions(): array
    {
        return [['create'], ['update']];
    }

    #[DataProvider('competingActions')]
    public function test_two_overlapping_proposals_cannot_both_execute_after_owner_serialization(string $name): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $one = $two = null;
        if ($name === 'update') {
            $one = StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']);
            $two = StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '10:00:00', 'ends_at' => '11:00:00']);
        }
        [, $conversationOne, $actionOne] = $this->fixture($name, $owner, $one);
        [, $conversationTwo, $actionTwo] = $this->fixture($name, $owner, $two);
        [$first, $gate] = $this->worker($owner, $conversationOne, $actionOne, ['gate_on' => 'availability_written']);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversationTwo, $actionTwo);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($first)['status']);
        $outcome = $this->outcome($second);
        $this->assertSame(409, $outcome['status']);
        $this->assertSame('action_stale', $outcome['body']['code']);
        $this->assertSame('pending', $actionTwo->fresh()->status);
        $this->assertNull($actionTwo->fresh()->receipt);
        $this->assertSame(1, $owner->studyAvailabilityWindows()->where('starts_at', '12:00:00')->count());
        $this->assertSame(1, AiPendingAction::where('status', 'executed')->count());
    }

    public function test_parallel_confirmation_replays_one_insert_and_one_receipt(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        [$first, $gate] = $this->worker($owner, $conversation, $action, ['gate_on' => 'availability_written']);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversation, $action);
        $this->waiting($connection, 'ai_conversations');
        $gate->write("resume\n");
        $one = $this->outcome($first);
        $two = $this->outcome($second);
        $this->assertSame(200, $one['status']);
        $this->assertSame(200, $two['status']);
        $this->assertTrue($two['body']['replayed']);
        $this->assertSame($one['body']['data']['receipt'], $two['body']['data']['receipt']);
        $this->assertDatabaseCount('study_availability_windows', 1);
        $this->assertSame(1, $conversation->messages()->where('tool_calls->version', 2)->count());
    }

    public function test_confirmation_waits_for_rest_owner_lock_then_rejects_current_changed_state(): void
    {
        [$owner, $conversation, $action, $window] = $this->fixture('update');
        [$first, $gate] = $this->worker($owner, $conversation, $action, ['operation' => 'availability_update',
            'availability_window_id' => $window->id, 'values' => ['starts_at' => '08:15:00'], 'gate_on' => 'availability_written']);
        $this->event($first, 'gate');
        [$second, , $connection] = $this->worker($owner, $conversation, $action);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($first)['status']);
        $outcome = $this->outcome($second);
        $this->assertSame(409, $outcome['status']);
        $this->assertSame('action_stale', $outcome['body']['code']);
        $this->assertSame('08:15:00', $window->fresh()->starts_at);
        $this->assertSame('pending', $action->fresh()->status);
    }

    public function test_expiry_during_business_write_rolls_back_window_receipt_and_history(): void
    {
        [$owner, $conversation, $action] = $this->fixture();
        DB::table('ai_pending_actions')->where('id', $action->id)->update(['expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        [$worker, $gate] = $this->worker($owner, $conversation, $action, ['gate_on' => 'availability_written']);
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
        $this->assertDatabaseCount('study_availability_windows', 0);
        $this->assertNull($action->fresh()->receipt);
        $this->assertSame('pending', $action->fresh()->status);
        $this->assertSame(0, $conversation->messages()->where('tool_calls->version', 2)->count());
    }
}
