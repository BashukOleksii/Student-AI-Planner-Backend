<?php

use App\Services\AI\LlmProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SystemTestProvider;

// This entry is never registered in production/bootstrap or public/. Fail before Laravel boot.
if (PHP_SAPI !== 'cli-server' || getenv('PLANNER_SYSTEM_TEST') !== '1' || ! is_file(getenv('PLANNER_SYSTEM_RUN').'/isolation.json')) {
    http_response_code(503);
    exit;
}
define('LARAVEL_START', microtime(true));
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$proof = json_decode(file_get_contents(getenv('PLANNER_SYSTEM_RUN').'/isolation.json'), true, 64, JSON_THROW_ON_ERROR);
$identity = $app->make('db')->selectOne('SELECT @@datadir AS datadir, @@server_uuid AS uuid, DATABASE() AS db');
if (! $app->environment('local') || $identity->datadir !== $proof['datadir'] || $identity->uuid !== $proof['uuid'] || $identity->db !== $proof['database']) {
    http_response_code(503);
    exit;
}
Http::preventStrayRequests();
$app->instance(LlmProvider::class, new SystemTestProvider);
$app->handleRequest(Request::capture());
