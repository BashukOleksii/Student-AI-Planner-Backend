<?php

use App\Exceptions\RecoverySuggestionException;
use App\Models\User;
use App\Services\Planning\RecoverySuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== TestDatabase::name()
    || $connection->selectOne('SELECT DATABASE() AS name')->name !== TestDatabase::name()) {
    throw new RuntimeException('Unsafe recovery concurrency test database.');
}
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
CarbonImmutable::setTestNow(CarbonImmutable::parse($input['now']));
$owner = User::findOrFail($input['owner_id']);
fwrite(STDOUT, json_encode(['ready' => true, 'connection_id' => $connection->selectOne('SELECT CONNECTION_ID() AS id')->id])."\n");
fflush(STDOUT);
if (isset($input['now_after_lock'])) {
    DB::listen(function ($query) use ($input): void {
        if (str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'for update')) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($input['now_after_lock']));
        }
    });
}
try {
    $result = $app->make(RecoverySuggestionService::class)->confirm($owner, $input['suggestion_id']);
    $outcome = ['status' => 'applied', 'replayed' => $result['replayed'],
        'receipt_hash' => hash('sha256', json_encode($result['suggestion']->receipt, JSON_THROW_ON_ERROR))];
} catch (RecoverySuggestionException $exception) {
    $outcome = ['status' => $exception->errorCode];
} catch (Throwable $exception) {
    $outcome = ['status' => 'error', 'class' => $exception::class];
}
fwrite(STDOUT, json_encode($outcome)."\n");
