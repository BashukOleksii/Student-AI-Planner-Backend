<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabase;

/** Independent clients inside one sequential suite; proves real InnoDB waits, never sleeps as proof. */
trait InteractsWithMysqlWorkers
{
    private function startMysqlWorker(string $script, array $input, array $arguments = []): Process
    {
        $config = DB::connection()->getConfig();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/'.$script), ...$arguments], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => TestDatabase::name(),
            'PLANNER_TEST_DATABASE' => TestDatabase::name(), 'DB_USERNAME' => $config['username'],
            'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => $config['unix_socket'] ?? '',
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $worker->setInput(json_encode($input, JSON_THROW_ON_ERROR));
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
                WHERE threads.PROCESSLIST_ID = ? AND locks.OBJECT_SCHEMA = DATABASE() AND locks.OBJECT_NAME = 'users'
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
