<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithMysqlWorkers;
use Tests\TestCase;

#[Group('mysql')]
#[Group('security')]
class DatabaseIsolationGuardTest extends TestCase
{
    use DatabaseTruncation, InteractsWithMysqlWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_second_suite_is_denied_before_database_cleanup_or_fixture_mutation(): void
    {
        User::factory()->create();
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $probe = $this->startMysqlWorker('../../vendor/bin/phpunit', [], [base_path('tests/Feature/ApplicationBootstrapTest.php')]);
        try {
            $this->assertNotSame(0, $probe->wait());
            $output = $probe->getOutput().$probe->getErrorOutput();
            $this->assertStringContainsString('Another destructive PHPUnit suite owns this MySQL database.', $output);
            $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        } finally {
            $probe->stop();
        }
    }
}
