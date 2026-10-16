<?php

// Test-only independent HTTP worker. Never migrate, truncate or contact an LLM.
use App\Models\User;
use App\Services\AI\LlmProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== 'student_planner_testing'
    || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'student_planner_testing') {
    throw new RuntimeException('Unsafe concurrency test database.');
}
$input = json_decode(fgets(STDIN), true, 64, JSON_THROW_ON_ERROR);
$app->instance(LlmProvider::class, new class($input['hold']) implements LlmProvider
{
    public function __construct(private bool $hold) {}

    public function respond(string $instructions, array $history, array $definitions): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider called inside a transaction.');
        }
        fwrite(STDOUT, json_encode(['provider_ready' => true, 'connection_id' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id])."\n");
        fflush(STDOUT);
        if ($this->hold && trim(fgets(STDIN) ?: '') !== 'resume') {
            throw new RuntimeException('Worker gate closed.');
        }

        return ['text' => 'Completed by independent worker.', 'calls' => [], 'state' => []];
    }
});
Auth::guard('web')->setUser(User::findOrFail($input['owner_id']));
$request = Request::create('/api/ai/conversations/'.$input['conversation_id'].'/messages', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $input['request_id'],
], json_encode(['content' => 'Concurrent question.']));
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
fwrite(STDOUT, json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)])."\n");
fflush(STDOUT);
$kernel->terminate($request, $response);
