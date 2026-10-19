<?php

// Independent guarded test worker. Never contacts a real provider.
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'mysql'
    || $db->getDatabaseName() !== 'student_planner_testing'
    || $db->selectOne('SELECT DATABASE() AS name')->name !== 'student_planner_testing'
    || config('cache.default') !== 'database') {
    throw new RuntimeException('Unsafe shared-cache test configuration.');
}
$input = json_decode(fgets(STDIN), true, 16, JSON_THROW_ON_ERROR);
config(['ai.openai_key' => 'offline-worker', 'ai.model' => 'gpt-5.4-mini', 'ai.requests_per_minute' => 1]);
Http::preventStrayRequests();
Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
    ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Hello.']]],
]])]);
Auth::guard('web')->setUser(User::findOrFail($input['owner']));
$request = Request::create('/api/ai/conversations/'.$input['conversation'].'/messages', 'POST', [], [], [],
    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $input['key']], '{"content":"Hello"}');
$response = $app->make(HttpKernel::class)->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true),
    'retry_after' => $response->headers->get('Retry-After'), 'provider_requests' => Http::recorded()->count(),
    'connection_id' => (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR)."\n";
