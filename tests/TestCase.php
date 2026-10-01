<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();

        // Fail before RefreshDatabase can run any destructive migration commands.
        $this->assertTrue($app->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('student_planner_testing', $connection->getDatabaseName());
        $this->assertSame('student_planner_testing', $connection->selectOne('SELECT DATABASE() AS name')->name);

        return $app;
    }
}
