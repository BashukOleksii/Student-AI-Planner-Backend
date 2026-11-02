<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();

        // Fail before RefreshDatabase can run any destructive migration commands.
        $this->assertTrue($app->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(TestDatabase::name(), $connection->getDatabaseName());
        $this->assertSame(TestDatabase::name(), $connection->selectOne('SELECT DATABASE() AS name')->name);

        return $app;
    }
}
