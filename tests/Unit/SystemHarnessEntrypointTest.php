<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SystemHarnessEntrypointTest extends TestCase
{
    public function test_fixture_cli_refuses_missing_opt_in_before_application_bootstrap(): void
    {
        $process = new Process([PHP_BINARY, 'tests/Support/system-fixtures.php', 'history', '1', '1'], dirname(__DIR__, 2), [
            'PLANNER_SYSTEM_TEST' => false, 'DB_CONNECTION' => 'intentionally_unavailable',
        ]);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_test_http_entry_cannot_boot_as_an_ordinary_cli_script_even_with_opt_in(): void
    {
        $process = new Process([PHP_BINARY, 'tests/Support/system-router.php'], dirname(__DIR__, 2), [
            'PLANNER_SYSTEM_TEST' => '1', 'PLANNER_SYSTEM_RUN' => '/nonexistent-system-test-proof',
            'DB_CONNECTION' => 'intentionally_unavailable',
        ]);
        $process->run();
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }
}
