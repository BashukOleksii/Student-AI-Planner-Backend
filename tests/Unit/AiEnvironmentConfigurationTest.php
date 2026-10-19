<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AiEnvironmentConfigurationTest extends TestCase
{
    public static function invalidValues(): array
    {
        return [['30oops'], ['30.5'], ['3e1'], [' 30'], ['030'], ['true'], ['false'], ['0'], ['-1'], ['999999999999999999999999']];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_numeric_environment_values_are_not_silently_coerced(string $value): void
    {
        $process = new Process([PHP_BINARY, '-r', "require 'vendor/autoload.php'; \$c = require 'config/ai.php'; echo json_encode(\$c['request_timeout']);"], dirname(__DIR__, 2), ['AI_REQUEST_TIMEOUT' => $value]);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        $this->assertSame('null', $process->getOutput());
    }

    public function test_live_smoke_is_unreachable_without_both_opt_in_flags_before_database_bootstrap(): void
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', 'tests/Live/OpenAiLiveSmokeTest.php'], dirname(__DIR__, 2),
            ['AI_LIVE_SMOKE' => '0', 'AI_LIVE_ALLOW_PAID' => '0', 'DB_DATABASE' => 'must_never_be_connected']);
        $this->assertNotSame(0, $process->run());
        $this->assertStringContainsString('Live smoke NOT RUN', $process->getOutput());
        $this->assertStringNotContainsString('SQLSTATE', $process->getOutput());
    }
}
