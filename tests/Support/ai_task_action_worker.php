<?php

// Independent test HTTP process: guarded MySQL only, no migrations and no upstream requests.
use App\Models\AiPendingAction;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\LlmProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== 'student_planner_testing'
    || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'student_planner_testing') {
    throw new RuntimeException('Unsafe concurrency test database.');
}
Http::preventStrayRequests();
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$input = json_decode(fgets(STDIN), true, 64, JSON_THROW_ON_ERROR);
$emit = function (array $event): void {
    fwrite(STDOUT, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
};
$gate = function (string $event) use ($input, $emit): void {
    $emit(['gate' => $event]);
    if (trim(fgets(STDIN) ?: '') !== 'resume') {
        throw new RuntimeException('Test worker gate closed.');
    }
    if ($input['fail_after_write'] ?? false) {
        throw new RuntimeException('Injected TaskService failure after business write.');
    }
};
if (($input['gate_on'] ?? null) === 'task_written') {
    $listener = function () use ($gate): void {
        $gate('task_written');
    };
    Task::created($listener);
    Task::updated($listener);
} elseif (($input['gate_on'] ?? null) === 'action_saved') {
    AiPendingAction::updated(fn () => $gate('action_saved'));
}
$app->instance(LlmProvider::class, new class($input, $emit, $gate) implements LlmProvider
{
    private int $round = 0;

    public function __construct(private array $input, private $emit, private $gate) {}

    public function respond(string $instructions, array $history, array $definitions): array
    {
        if ($this->input['operation'] !== 'chat' || DB::transactionLevel() !== 0) {
            throw new RuntimeException('Unexpected provider invocation or network transaction.');
        }
        if (++$this->round === 1) {
            return ['text' => null, 'calls' => [['id' => 'call_'.str_replace('-', '', $this->input['request_id']),
                'name' => 'propose_task_create', 'arguments' => ['title' => 'Independent proposal']]], 'state' => []];
        }
        if ($this->input['hold_provider'] ?? false) {
            $proposal = json_decode($history[array_key_last($history)]['content'], true)['data']['action_id'];
            ($this->emit)(['prepared_action_id' => $proposal]);
            ($this->gate)('proposal_prepared');
        }

        return ['text' => 'Proposed.', 'calls' => [], 'state' => []];
    }
});
Auth::guard('web')->setUser(User::findOrFail($input['owner_id']));
$operation = $input['operation'];
$path = match ($operation) {
    'confirm', 'reject' => '/api/ai/conversations/'.$input['conversation_id'].'/actions/'.$input['action_id'].'/'.$operation,
    'chat' => '/api/ai/conversations/'.$input['conversation_id'].'/messages',
    'task_update' => '/api/tasks/'.$input['task_id'],
    'task_complete' => '/api/tasks/'.$input['task_id'].'/complete',
    'task_reopen' => '/api/tasks/'.$input['task_id'].'/reopen',
};
$body = match ($operation) {
    'chat' => ['content' => 'Independent question.'], 'task_update' => ['title' => 'Direct concurrent edit'], default => (object) [],
};
$request = Request::create($path, $operation === 'task_update' ? 'PATCH' : 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_IDEMPOTENCY_KEY' => $input['request_id'] ?? '',
], json_encode($body, JSON_THROW_ON_ERROR));
$emit(['ready' => true, 'connection_id' => (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id]);
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
if (($input['gate_on'] ?? null) === 'response_committed') {
    $gate('response_committed');
}
$emit(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
$kernel->terminate($request, $response);
