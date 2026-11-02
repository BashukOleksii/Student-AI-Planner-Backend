<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CiMigrationVerificationTest extends TestCase
{
    public static function unsafeContexts(): array
    {
        return [
            ['CI', 'false'], ['GITHUB_ACTIONS', 'false'], ['RUNNER_ENVIRONMENT', 'self-hosted'],
            ['GITHUB_REPOSITORY', 'another/repository'], ['GITHUB_WORKFLOW', 'another workflow'], ['GITHUB_JOB', 'deploy'],
            ['APP_ENV', 'production'], ['DB_CONNECTION', 'sqlite'], ['DB_URL', 'mysql://elsewhere'],
            ['DB_HOST', 'shared.example.com'], ['DB_PORT', '3307'], ['DB_DATABASE', 'student_planner'],
            ['GITHUB_WORKSPACE', '/tmp'], ['GITHUB_EVENT_NAME', 'schedule'],
            ['DISPOSABLE_CI_MYSQL_CONTAINER_ID', ''], ['DISPOSABLE_CI_MYSQL_CONTAINER_ID', 'not-a-container'],
        ];
    }

    #[DataProvider('unsafeContexts')]
    public function test_unsafe_context_is_rejected_before_bootstrap_or_any_database_access(string $field, string $value): void
    {
        $root = dirname(__DIR__, 2);
        $environment = [
            'CI' => 'true', 'GITHUB_ACTIONS' => 'true', 'RUNNER_ENVIRONMENT' => 'github-hosted',
            'GITHUB_REPOSITORY' => 'BashukOleksii/Student-AI-Planner-Backend',
            'GITHUB_WORKFLOW' => 'Backend verification', 'GITHUB_JOB' => 'verify',
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'student_planner_testing',
            'GITHUB_WORKSPACE' => $root, 'GITHUB_EVENT_NAME' => 'push',
            'DISPOSABLE_CI_MYSQL_CONTAINER_ID' => str_repeat('a', 64),
            $field => $value,
        ];
        // A fake PHP bootstrap makes any attempted Laravel/database access a test failure.
        $bootstrap = tempnam(sys_get_temp_dir(), 'ci-migration-guard-');
        file_put_contents($bootstrap, '<?php spl_autoload_register(static function () { throw new RuntimeException("UNEXPECTED_BOOTSTRAP"); });');
        try {
            $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file='.$bootstrap, $root.'/.github/scripts/verify-ci-migrations.php'], $root, $environment);
            $this->assertNotSame(0, $process->run());
            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertStringNotContainsString('UNEXPECTED_BOOTSTRAP', $output);
            $this->assertStringContainsString('RuntimeException', $output);
            $this->assertStringNotContainsString('SQLSTATE', $output);
            $this->assertStringNotContainsString('Migrating', $output);
        } finally {
            unlink($bootstrap);
        }
    }
}
