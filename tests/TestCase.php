<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\TestDatabase;

abstract class TestCase extends BaseTestCase
{
    private static $databaseLease = null;

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();

        // Fail before RefreshDatabase can run any destructive migration commands.
        $this->assertTrue($app->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(TestDatabase::name(), $connection->getDatabaseName());
        $identity = $connection->selectOne('SELECT DATABASE() AS name, @@server_uuid AS server_id');
        $this->assertSame(TestDatabase::name(), $identity->name);
        if (self::$databaseLease === null) {
            $lease = fopen(sys_get_temp_dir().'/planner-phpunit-'.hash('sha256', $identity->server_id.':'.$identity->name).'.lock', 'c');
            if ($lease === false || ! flock($lease, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another destructive PHPUnit suite owns this MySQL database.');
            }
            self::$databaseLease = $lease;
        }
        if (! str_starts_with(static::class, 'Tests\\Live\\')) {
            Http::preventStrayRequests();
        }

        return $app;
    }
}
