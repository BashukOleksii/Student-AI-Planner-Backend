<?php

namespace Tests\Unit;

use App\Services\AI\GeminiGenerateContentProvider;
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

    public function test_default_provider_and_models_are_independent(): void
    {
        $process = new Process([PHP_BINARY, '-r', "require 'vendor/autoload.php'; \$c = require 'config/ai.php'; echo json_encode([\$c['provider'], \$c['gemini_model'], \$c['model']]);"], dirname(__DIR__, 2),
            ['AI_PROVIDER' => false, 'GEMINI_MODEL' => false, 'OPENAI_MODEL' => 'gpt-5.4-mini']);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        $this->assertSame('["gemini","gemini-2.5-flash","gpt-5.4-mini"]', $process->getOutput());
    }

    public function test_provider_configuration_survives_laravel_config_caching(): void
    {
        $cacheDirectory = sys_get_temp_dir().'/planner-provider-config-'.bin2hex(random_bytes(8));
        mkdir($cacheDirectory, 0700);
        $cache = $cacheDirectory.'/config.php';
        $root = dirname(__DIR__, 2);
        $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $cache, 'AI_PROVIDER' => 'gemini',
            'GEMINI_MODEL' => 'gemini-2.5-flash', 'GEMINI_API_KEY' => 'offline-google-key',
            'OPENAI_MODEL' => 'gpt-5.4-mini', 'OPENAI_API_KEY' => 'offline-openai-key'];
        try {
            $cacheProcess = new Process([PHP_BINARY, 'artisan', 'config:cache'], $root, $environment);
            $this->assertSame(0, $cacheProcess->run(), $cacheProcess->getErrorOutput());
            $code = "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode([config('ai.provider'), config('ai.gemini_model'), config('ai.model'), get_class(app(App\\Services\\AI\\LlmProvider::class))]);";
            $read = new Process([PHP_BINARY, '-r', $code], $root, [...$environment, 'AI_PROVIDER' => 'openai', 'GEMINI_MODEL' => 'changed-env']);
            $this->assertSame(0, $read->run(), $read->getErrorOutput());
            $this->assertSame(['gemini', 'gemini-2.5-flash', 'gpt-5.4-mini', GeminiGenerateContentProvider::class], json_decode($read->getOutput(), true));
        } finally {
            if (file_exists($cache)) {
                unlink($cache);
            }
            rmdir($cacheDirectory);
        }
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
