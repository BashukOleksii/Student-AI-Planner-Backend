<?php

namespace Tests\Support;

use RuntimeException;

/** A deliberate separate selector; DB_DATABASE alone cannot widen the destructive-test guard. */
class TestDatabase
{
    public static function name(): string
    {
        $name = getenv('PLANNER_TEST_DATABASE') ?: 'student_planner_testing';
        if ($name !== 'student_planner_testing' && ! preg_match('/\Astudent_planner_isolated_[a-z0-9_]{1,32}\z/', $name)) {
            throw new RuntimeException('Unsafe isolated test database name.');
        }

        return $name;
    }
}
