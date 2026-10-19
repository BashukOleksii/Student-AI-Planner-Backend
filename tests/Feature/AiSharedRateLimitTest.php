<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AiSharedRateLimitTest extends TestCase
{
    use DatabaseTruncation;

    public function test_independent_php_workers_share_the_database_throttle_and_isolate_other_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = AiConversation::factory()->for($owner)->create();
        $otherConversation = AiConversation::factory()->for($other)->create();
        $prefix = 'h4-rate-'.Str::uuid().'-';
        $config = DB::connection()->getConfig();
        $workers = [];
        $start = function ($user, $conversation) use ($config, $prefix, &$workers): Process {
            $worker = new Process([PHP_BINARY, base_path('tests/Support/ai_rate_limit_worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_DEBUG' => 'true', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => 'student_planner_testing',
                'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => $config['unix_socket'] ?? '',
                'CACHE_STORE' => 'database', 'CACHE_PREFIX' => $prefix, 'SESSION_DRIVER' => 'array',
            ], json_encode(['owner' => $user->id, 'conversation' => $conversation->id, 'key' => (string) Str::uuid()])."\n", 15);
            $workers[] = $worker;
            $worker->start();

            return $worker;
        };
        $result = function (Process $worker): array {
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $result = json_decode(trim($worker->getOutput()), true, 16, JSON_THROW_ON_ERROR);
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $result['connection_id']);

            return $result;
        };
        try {
            $first = $result($start($owner, $conversation));
            $this->assertSame(200, $first['status']);
            $this->assertSame(1, $first['provider_requests']);
            // Saturated same-user requests run simultaneously in separate processes.
            $two = $start($owner, $conversation);
            $three = $start($owner, $conversation);
            $independent = $start($other, $otherConversation);
            foreach ([$result($two), $result($three)] as $blocked) {
                $this->assertSame(429, $blocked['status']);
                $this->assertSame(['message' => 'Too Many Attempts.'], $blocked['body']);
                $this->assertSame(0, $blocked['provider_requests']);
                $this->assertGreaterThan(0, (int) $blocked['retry_after']);
            }
            $this->assertSame(200, $result($independent)['status']);
            $this->assertGreaterThan(0, DB::table('cache')->where('key', 'like', $prefix.'%')->count());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            DB::table('cache')->where('key', 'like', $prefix.'%')->delete();
            $this->truncateTablesForAllConnections();
        }
    }
}
