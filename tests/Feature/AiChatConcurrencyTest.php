<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AiChatConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->workers as [$process, $stream]) {
                $process->stop();
                $stream->close();
            }
            $this->truncateTablesForAllConnections();
        });
    }

    private function worker(User $owner, AiConversation $conversation, string $requestId, bool $hold): array
    {
        $stream = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/ai_chat_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_DATABASE' => 'student_planner_testing',
            'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], $stream, 15);
        $stream->write(json_encode(['owner_id' => $owner->id, 'conversation_id' => $conversation->id, 'request_id' => $requestId, 'hold' => $hold])."\n");
        $this->workers[] = [$process, $stream];
        $process->start();

        return [$process, $stream];
    }

    private function ready(Process $process): int
    {
        $found = false;
        $process->waitUntil(function ($type, $output) use ($process, &$found) {
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                $item = json_decode($line, true);
                if (($item['provider_ready'] ?? false) === true) {
                    $found = $item['connection_id'];

                    return true;
                }
            }

            return false;
        });
        $this->assertNotFalse($found, 'Independent provider did not reach its gate. '.$process->getErrorOutput());

        return $found;
    }

    private function outcome(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), 'Independent worker failed. '.$process->getErrorOutput());
        foreach (array_reverse(explode("\n", trim($process->getOutput()))) as $line) {
            $item = json_decode($line, true);
            if (isset($item['status'])) {
                return $item;
            }
        }
        throw new RuntimeException('Missing worker outcome.');
    }

    public function test_overlapping_rest_turns_conflict_without_network_locks_and_successful_retry_replays_once(): void
    {
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $key = (string) Str::uuid();
        [$first, $gate] = $this->worker($owner, $conversation, $key, true);
        $connection = $this->ready($first);
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $connection);
        // The first request waits for the provider while another connection can lock its conversation.
        DB::transaction(function () use ($conversation): void {
            $this->assertNotNull(AiConversation::whereKey($conversation->id)->lockForUpdate()->first());
        });
        [$second] = $this->worker($owner, $conversation, (string) Str::uuid(), false);
        $conflict = $this->outcome($second);
        $this->assertSame(409, $conflict['status']);
        $this->assertSame('conversation_busy', $conflict['body']['code']);
        $this->assertDatabaseCount('ai_messages', 0);
        $gate->write("resume\n");
        $completed = $this->outcome($first);
        $this->assertSame(200, $completed['status']);
        [$retry] = $this->worker($owner, $conversation, $key, false);
        $this->assertSame($completed, $this->outcome($retry));
        $this->assertStringNotContainsString('provider_ready', $retry->getOutput());
        $this->assertDatabaseCount('ai_messages', 2);
        $this->assertDatabaseCount('ai_conversation_turns', 1);
    }

    public static function recoveryKeys(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('recoveryKeys')]
    public function test_expired_worker_cannot_finalize_or_release_replacement_claim(bool $sameKey): void
    {
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $key = (string) Str::uuid();
        [$first, $firstGate] = $this->worker($owner, $conversation, $key, true);
        $this->ready($first);
        $oldToken = DB::table('ai_conversation_turns')->sole()->lease_token;
        DB::table('ai_conversation_turns')->update(['lease_expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        [$second, $secondGate] = $this->worker($owner, $conversation, $sameKey ? $key : (string) Str::uuid(), true);
        $this->ready($second);
        $active = DB::table('ai_conversation_turns')->where('lease_expires_at', '>', DB::raw('UTC_TIMESTAMP(6)'))->sole();
        $this->assertNotSame($oldToken, $active->lease_token);
        $firstGate->write("resume\n");
        $stale = $this->outcome($first);
        $this->assertSame(409, $stale['status']);
        $this->assertSame('turn_expired', $stale['body']['code']);
        $this->assertSame($active->lease_token, DB::table('ai_conversation_turns')->where('id', $active->id)->value('lease_token'));
        $this->assertDatabaseCount('ai_messages', 0);
        $secondGate->write("resume\n");
        $this->assertSame(200, $this->outcome($second)['status']);
        $this->assertDatabaseCount('ai_messages', 2);
        $this->assertSame(1, DB::table('ai_conversation_turns')->whereNotNull('result')->count());
    }

    public function test_abandoned_process_recovers_after_expiry_and_separate_conversations_can_run_concurrently(): void
    {
        $owner = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $other = AiConversation::factory()->for($owner)->create();
        $key = (string) Str::uuid();
        [$abandoned] = $this->worker($owner, $conversation, $key, true);
        $this->ready($abandoned);
        [$independent] = $this->worker($owner, $other, (string) Str::uuid(), false);
        $this->assertSame(200, $this->outcome($independent)['status']);
        $abandoned->stop(0, SIGKILL);
        DB::table('ai_conversation_turns')->where('conversation_id', $conversation->id)
            ->update(['lease_expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        [$recovery] = $this->worker($owner, $conversation, $key, false);
        $this->assertSame(200, $this->outcome($recovery)['status']);
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertSame(2, $other->messages()->count());
    }
}
