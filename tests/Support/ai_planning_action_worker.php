<?php

// Guarded independent HTTP worker. Test-only provider, no upstream or migration calls.
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\LlmProvider;
use App\Services\Reminders\ReminderDeliveryService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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
    throw new RuntimeException('Unsafe planning concurrency database.');
}
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
Http::preventStrayRequests();
$input = json_decode(fgets(STDIN), true, 64, JSON_THROW_ON_ERROR);
if (isset($input['now'])) {
    Carbon::setTestNow(CarbonImmutable::parse($input['now'], 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($input['now'], 'UTC'));
}
$emit = function (array $event): void {
    fwrite(STDOUT, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
};
$gated = false;
$gate = function (string $event) use ($input, $emit, &$gated): void {
    if ($gated) {
        return;
    }
    $gated = true;
    $emit(['gate' => $event]);
    if (trim(fgets(STDIN) ?: '') !== 'resume') {
        throw new RuntimeException('Planning test gate closed.');
    }
    if ($input['fail_after_write'] ?? false) {
        throw new RuntimeException('Injected failure after persisted business or receipt change.');
    }
};
$event = $input['gate_on'] ?? null;
if ($event === 'session_written') {
    StudySession::created(fn () => $gate('session_written'));
    StudySession::updated(fn () => $gate('session_written'));
} elseif ($event === 'action_written') {
    AiPendingAction::updated(fn () => $gate('action_written'));
} elseif ($event === 'task_written') {
    Task::updated(fn () => $gate('task_written'));
} elseif ($event === 'availability_written') {
    StudyAvailabilityWindow::updated(fn () => $gate('availability_written'));
} elseif ($event === 'reminder_written') {
    Reminder::updated(fn () => $gate('reminder_written'));
} elseif ($event === 'preferences_written') {
    PlanningPreference::created(fn () => $gate('preferences_written'));
    PlanningPreference::updated(fn () => $gate('preferences_written'));
} elseif ($event === 'history_write') {
    AiMessage::creating(fn () => $gate('history_write'));
}
$app->instance(LlmProvider::class, new class($input, $emit, $gate) implements LlmProvider
{
    private int $round = 0;

    public function __construct(private array $input, private $emit, private $gate) {}

    public function respond(string $instructions, array $history, array $definitions): array
    {
        if ($this->input['operation'] !== 'chat' || DB::transactionLevel() !== 0) {
            throw new RuntimeException('Unexpected provider work during confirmation or a transaction.');
        }
        if (++$this->round === 1) {
            return ['text' => null, 'state' => [], 'calls' => [['id' => 'planning_call_'.str_replace('-', '', $this->input['request_id']),
                'name' => $this->input['name'], 'arguments' => $this->input['arguments']]]];
        }
        if ($this->input['hold_provider'] ?? false) {
            $tool = json_decode($history[array_key_last($history)]['content'], true);
            ($this->emit)(['prepared_action_id' => $tool['data']['action_id'] ?? null]);
            ($this->gate)('provider_wait');
        }

        return ['text' => 'Proposal reviewed.', 'calls' => [], 'state' => []];
    }
});
Auth::guard('web')->setUser(User::findOrFail($input['owner_id']));
$operation = $input['operation'];
$emit(['ready' => true, 'connection_id' => (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id]);
if ($operation === 'delivery') {
    $result = $app->make(ReminderDeliveryService::class)->process($input['owner_id'], $input['reminder_id']);
    $emit(['status' => 200, 'body' => $result]);
    exit;
}
$path = match ($operation) {
    'confirm', 'reject' => '/api/ai/conversations/'.$input['conversation_id'].'/actions/'.$input['action_id'].'/'.$operation,
    'chat' => '/api/ai/conversations/'.$input['conversation_id'].'/messages',
    'direct_plan' => '/api/planning/study-plans/confirm',
    'direct_reschedule' => '/api/study-sessions/'.$input['source_id'].'/reschedule',
    'cancel_session' => '/api/study-sessions/'.$input['source_id'].'/cancel',
    'availability' => '/api/study-availability-windows/'.$input['window_id'],
    'deadline' => '/api/tasks/'.$input['task_id'],
    'subtask_deadline' => '/api/tasks/'.$input['task_id'].'/subtasks/'.$input['subtask_id'],
    'preferences' => '/api/planning-preferences',
    'reminder_update' => '/api/reminders/'.$input['reminder_id'],
};
$body = match ($operation) {
    'confirm' => $input['consent'] ?? (object) [], 'reject', 'cancel_session' => (object) [],
    'chat' => ['content' => 'Independent planning request.'], default => $input['body'],
};
$request = Request::create($path, match ($operation) {
    'availability', 'deadline', 'subtask_deadline', 'reminder_update' => 'PATCH', 'preferences' => 'PUT', default => 'POST',
}, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $input['request_id'] ?? ''], json_encode($body, JSON_THROW_ON_ERROR));
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
if ($event === 'response_committed') {
    $gate('response_committed');
}
$emit(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
$kernel->terminate($request, $response);
