<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

class AiManagementActionConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.openai_key' => 'fake', 'ai.model' => 'gpt-5.4-mini']);
        Http::preventStrayRequests();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->workers as [$process, $stream]) {
                $process->stop();
                $stream->close();
            }
            $this->truncateTablesForAllConnections();
        });
    }

    private function fixture(string $name = 'propose_subtask_create', ?User $owner = null, bool $relative = false): array
    {
        $owner ??= User::factory()->create(['timezone' => 'UTC']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $task = Task::factory()->for($owner)->create(['deadline_at' => now('UTC')->addDays(5)->startOfSecond()]);
        $child = Subtask::factory()->for($task)->create(['deadline_at' => now('UTC')->addDays(4)->startOfSecond()]);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id,
            'starts_at' => now('UTC')->addDays(3)->startOfSecond(), 'ends_at' => now('UTC')->addDays(3)->addHour()->startOfSecond()]);
        $reminder = app(ReminderService::class)->create($owner, ['message' => 'Original', 'trigger_at' => now('UTC')->addDays(2)->startOfSecond()->format('Y-m-d\TH:i:s\Z')]);
        $arguments = match ($name) {
            'propose_subtask_create' => ['task_id' => $task->id, 'title' => 'Exactly once'],
            'propose_subtask_update' => ['task_id' => $task->id, 'subtask_id' => $child->id, 'title' => 'Confirmed edit'],
            'propose_subtask_complete' => ['task_id' => $task->id, 'subtask_id' => $child->id],
            'propose_reminder_create' => $relative ? ['task_id' => $task->id, 'anchor' => 'task_deadline', 'offset_minutes' => -60]
                : ['message' => 'Exactly once', 'trigger_at' => now('UTC')->addDay()->startOfSecond()->format('Y-m-d\TH:i:s\Z')],
            'propose_reminder_update' => ['reminder_id' => $reminder->id, 'message' => 'Confirmed edit'],
            'propose_reminder_cancel' => ['reminder_id' => $reminder->id],
        };
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall($name, json_encode($arguments))]))
            ->push($this->aiResponse([$this->aiText()]));
        $this->app['auth']->forgetGuards();
        $id = $this->actingAs($owner, 'web')->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Propose one action.'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data.action.id');
        $action = AiPendingAction::findOrFail($id);

        return compact('owner', 'conversation', 'task', 'child', 'session', 'reminder', 'action');
    }

    private function start(array $f, array $options = []): array
    {
        return $this->worker($f['owner'], $f['conversation'], $f['action'], ['task_id' => $f['task']->id,
            'subtask_id' => $f['child']->id, 'reminder_id' => $f['reminder']->id, ...$options]);
    }

    private function worker(User $owner, AiConversation $conversation, ?AiPendingAction $action, array $options = []): array
    {
        $stream = new InputStream;
        $config = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/ai_management_action_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => 'student_planner_testing',
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => $config['unix_socket'] ?? '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], $stream, 20);
        $stream->write(json_encode(['owner_id' => $owner->id, 'conversation_id' => $conversation->id, 'action_id' => $action?->id,
            'operation' => 'confirm', ...$options], JSON_THROW_ON_ERROR)."\n");
        $this->workers[] = [$process, $stream];
        $process->start();
        $ready = $this->event($process, 'ready');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $ready['connection_id']);

        return [$process, $stream, $ready['connection_id']];
    }

    private function event(Process $worker, string $key): array
    {
        $deadline = microtime(true) + 5;
        do {
            foreach (explode("\n", trim($worker->getOutput())) as $line) {
                $event = json_decode($line, true);
                if (isset($event[$key])) {
                    return $event;
                }
            }
            if (! $worker->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Missing worker event '.$key.': '.$worker->getErrorOutput().' '.$worker->getOutput());
    }

    private function outcome(Process $worker): array
    {
        $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
        foreach (array_reverse(explode("\n", trim($worker->getOutput()))) as $line) {
            $event = json_decode($line, true);
            if (isset($event['status'])) {
                return $event;
            }
        }
        throw new RuntimeException('Missing HTTP worker outcome.');
    }

    private function waiting(int $connection, string $table): void
    {
        $deadline = microtime(true) + 5;
        do {
            $wait = DB::selectOne(<<<'SQL'
                SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits AS waits
                JOIN performance_schema.threads AS threads ON waits.REQUESTING_THREAD_ID = threads.THREAD_ID
                JOIN performance_schema.data_locks AS locks ON waits.REQUESTING_ENGINE_LOCK_ID = locks.ENGINE_LOCK_ID
                WHERE threads.PROCESSLIST_ID = ? AND locks.OBJECT_SCHEMA = 'student_planner_testing' AND locks.OBJECT_NAME = ?
                SQL, [$connection, $table]);
            if ((int) $wait->total > 0) {
                $this->assertGreaterThan(0, (int) $wait->total);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('No real MySQL lock contention on '.$table);
    }

    public static function creationDomains(): array
    {
        return [['propose_subtask_create', 'subtasks', 'subtask_written'], ['propose_reminder_create', 'reminders', 'reminder_written']];
    }

    #[DataProvider('creationDomains')]
    public function test_two_confirmations_share_one_committed_mutation_and_receipt(string $name, string $table, string $gateOn): void
    {
        $f = $this->fixture($name);
        $count = DB::table($table)->count();
        [$one, $gate] = $this->start($f, ['gate_on' => $gateOn]);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f);
        $this->waiting($connection, 'ai_conversations');
        $this->assertSame($count, DB::table($table)->count());
        $gate->write("resume\n");
        $first = $this->outcome($one);
        $second = $this->outcome($two);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertEquals($first['body']['data']['receipt'], $second['body']['data']['receipt']);
        $this->assertTrue($second['body']['replayed']);
        $this->assertSame($count + 1, DB::table($table)->count());
        $this->assertSame(1, $f['conversation']->messages()->where('tool_calls->version', 2)->count());
    }

    public static function orderings(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('orderings')]
    public function test_confirmation_and_rejection_serialize_one_terminal_outcome(bool $confirmFirst): void
    {
        $f = $this->fixture('propose_reminder_create');
        [$one, $gate] = $this->start($f, ['operation' => $confirmFirst ? 'confirm' : 'reject', 'gate_on' => 'action_saved']);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f, ['operation' => $confirmFirst ? 'reject' : 'confirm']);
        $this->waiting($connection, 'ai_conversations');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($one)['status']);
        $this->assertSame(409, $this->outcome($two)['status']);
        $this->assertSame($confirmFirst ? 'executed' : 'rejected', $f['action']->fresh()->status);
        $this->assertDatabaseCount('reminders', $confirmFirst ? 2 : 1);
    }

    public static function updateRaces(): array
    {
        return [['propose_subtask_update', 'subtask_update', 'subtask_written', true], ['propose_subtask_update', 'subtask_update', 'subtask_written', false],
            ['propose_reminder_update', 'reminder_update', 'reminder_written', true], ['propose_reminder_update', 'reminder_update', 'reminder_written', false]];
    }

    #[DataProvider('updateRaces')]
    public function test_direct_updates_and_confirmation_never_overwrite_unreviewed_state(string $name, string $direct, string $gateOn, bool $confirmFirst): void
    {
        $f = $this->fixture($name);
        [$one, $gate] = $this->start($f, ['operation' => $confirmFirst ? 'confirm' : $direct, 'gate_on' => $gateOn]);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f, ['operation' => $confirmFirst ? $direct : 'confirm']);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($one)['status']);
        $result = $this->outcome($two);
        $this->assertSame($confirmFirst ? 200 : 409, $result['status']);
        if (! $confirmFirst) {
            $this->assertSame('action_stale', $result['body']['code']);
        }
        $this->assertSame($confirmFirst ? 'executed' : 'pending', $f['action']->fresh()->status);
        $this->assertSame($direct === 'subtask_update' ? 'Direct concurrent child edit' : 'Direct concurrent reminder edit',
            $direct === 'subtask_update' ? $f['child']->fresh()->title : $f['reminder']->fresh()->message);
    }

    #[DataProvider('orderings')]
    public function test_subtask_completion_and_direct_lifecycle_preserve_current_status_parent_and_sessions(bool $confirmFirst): void
    {
        $f = $this->fixture('propose_subtask_complete');
        $task = $f['task']->fresh()->getAttributes();
        $session = $f['session']->fresh()->getAttributes();
        [$one, $gate] = $this->start($f, ['operation' => $confirmFirst ? 'confirm' : 'subtask_complete', 'gate_on' => 'subtask_written']);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f, ['operation' => $confirmFirst ? 'subtask_reopen' : 'confirm']);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($one)['status']);
        $this->assertSame($confirmFirst ? 200 : 409, $this->outcome($two)['status']);
        $this->assertSame($confirmFirst ? 'pending' : 'completed', $f['child']->fresh()->status->value);
        $this->assertSame($task, $f['task']->fresh()->getAttributes());
        $this->assertSame($session, $f['session']->fresh()->getAttributes());
    }

    #[DataProvider('orderings')]
    public function test_reminder_cancellation_and_delivery_never_overwrite_a_delivered_record_or_delete_inbox_history(bool $confirmFirst): void
    {
        $f = $this->fixture('propose_reminder_cancel');
        $clock = CarbonImmutable::parse($f['reminder']->getRawOriginal('trigger_at'), 'UTC')->addMinute()->toISOString();
        [$one, $gate] = $this->start($f, ['operation' => $confirmFirst ? 'confirm' : 'delivery', 'gate_on' => 'reminder_written', 'clock' => $clock]);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f, ['operation' => $confirmFirst ? 'delivery' : 'confirm', 'clock' => $clock]);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($one)['status']);
        $second = $this->outcome($two);
        $this->assertSame($confirmFirst ? 200 : 409, $second['status']);
        if ($confirmFirst) {
            $this->assertSame('skipped', $second['body']['status']);
        } else {
            $this->assertSame('action_stale', $second['body']['code']);
        }
        $this->assertSame($confirmFirst ? 'cancelled' : 'sent', $f['reminder']->fresh()->status->value);
        $this->assertDatabaseCount('notifications', $confirmFirst ? 0 : 1);
        $this->assertSame($confirmFirst ? 'executed' : 'pending', $f['action']->fresh()->status);
    }

    #[DataProvider('orderings')]
    public function test_relative_creation_and_concurrent_deadline_change_use_reviewed_and_current_anchors(bool $confirmFirst): void
    {
        $f = $this->fixture('propose_reminder_create', relative: true);
        $oldTrigger = $f['action']->preview['changes']['trigger_at'];
        $newDeadline = CarbonImmutable::parse($f['task']->getRawOriginal('deadline_at'), 'UTC')->addDay();
        $data = ['deadline_at' => $newDeadline->format('Y-m-d\TH:i:s\Z')];
        [$one, $gate] = $this->start($f, ['operation' => $confirmFirst ? 'confirm' : 'task_deadline',
            'gate_on' => $confirmFirst ? 'reminder_written' : 'task_written', 'data' => $data]);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f, ['operation' => $confirmFirst ? 'task_deadline' : 'confirm', 'data' => $data]);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $first = $this->outcome($one);
        $this->assertSame(200, $first['status']);
        $this->assertSame($confirmFirst ? 200 : 409, $this->outcome($two)['status']);
        if ($confirmFirst) {
            $receipt = $first['body']['data']['receipt']['reminder'];
            $this->assertSame($oldTrigger, $receipt['trigger_at']);
            $this->assertSame($newDeadline->subHour()->format('Y-m-d H:i:s'), Reminder::findOrFail($receipt['id'])->getRawOriginal('trigger_at'));
        }
        $this->assertDatabaseCount('reminders', $confirmFirst ? 2 : 1);
    }

    public function test_owner_wait_observes_newly_committed_target_anchor_and_rejects_stale_creation(): void
    {
        $f = $this->fixture('propose_reminder_create', relative: true);
        DB::beginTransaction();
        User::whereKey($f['owner']->id)->lockForUpdate()->firstOrFail();
        try {
            [$worker, , $connection] = $this->start($f);
            $this->waiting($connection, 'users');
            app(TaskService::class)->update($f['owner'], $f['task'], ['deadline_at' => CarbonImmutable::parse($f['task']->getRawOriginal('deadline_at'), 'UTC')->addDay()->format('Y-m-d\TH:i:s\Z')]);
            DB::commit();
            $result = $this->outcome($worker);
            $this->assertSame(409, $result['status']);
            $this->assertSame('action_stale', $result['body']['code']);
            $this->assertDatabaseCount('reminders', 1);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
        }
    }

    public function test_owner_wait_does_not_hide_new_subtask_session_reminders_from_completion(): void
    {
        $f = $this->fixture('propose_subtask_complete');
        DB::beginTransaction();
        User::whereKey($f['owner']->id)->lockForUpdate()->firstOrFail();
        try {
            [$worker, , $connection] = $this->start($f);
            $this->waiting($connection, 'users');
            $reminder = Reminder::factory()->for($f['owner'])->create(['study_session_id' => $f['session']->id]);
            DB::commit();
            $this->assertSame(200, $this->outcome($worker)['status']);
            $this->assertSame('cancelled', $reminder->fresh()->status->value);
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
        }
    }

    private function expireSoon(array $f): void
    {
        DB::table('ai_pending_actions')->where('id', $f['action']->id)->update(['expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
    }

    private function awaitExpiry(array $f): void
    {
        $deadline = microtime(true) + 3;
        do {
            $expired = DB::selectOne('SELECT expires_at <= UTC_TIMESTAMP(6) AS elapsed FROM ai_pending_actions WHERE id = ?', [$f['action']->id])->elapsed;
            if ($expired) {
                $this->assertTrue((bool) $expired);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Expected authoritative MySQL expiry.');
    }

    #[DataProvider('creationDomains')]
    public function test_expiration_after_owner_lock_wait_prevents_business_execution(string $name, string $table, string $gateOn): void
    {
        $f = $this->fixture($name);
        $count = DB::table($table)->count();
        $this->expireSoon($f);
        DB::beginTransaction();
        User::whereKey($f['owner']->id)->lockForUpdate()->firstOrFail();
        try {
            [$worker, , $connection] = $this->start($f);
            $this->waiting($connection, 'users');
            $this->awaitExpiry($f);
            DB::commit();
            $this->assertSame(410, $this->outcome($worker)['status']);
            $this->assertSame('expired', $f['action']->fresh()->status);
            $this->assertSame($count, DB::table($table)->count());
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
        }
    }

    #[DataProvider('creationDomains')]
    public function test_expiration_while_waiting_for_a_domain_lock_rolls_back_without_a_receipt(string $name, string $table, string $gateOn): void
    {
        // Hold only the domain row: the confirmer acquires its owner lock before blocking here.
        $f = $this->fixture($name, relative: $name === 'propose_reminder_create');
        $count = DB::table($table)->count();
        $this->expireSoon($f);
        DB::beginTransaction();
        Task::whereKey($f['task']->id)->lockForUpdate()->firstOrFail();
        try {
            [$worker, , $connection] = $this->start($f);
            $this->waiting($connection, 'tasks');
            $this->awaitExpiry($f);
            DB::commit();
            $result = $this->outcome($worker);
            $this->assertSame(410, $result['status']);
            $this->assertSame('action_expired', $result['body']['code']);
            $this->assertSame($count, DB::table($table)->count());
            $this->assertSame('pending', $f['action']->fresh()->status);
            $this->assertNull($f['action']->fresh()->receipt);
            $this->assertSame(0, $f['conversation']->messages()->where('tool_calls->version', 2)->count());
            [$retry] = $this->start($f);
            $this->assertSame(410, $this->outcome($retry)['status']);
            $this->assertSame('expired', $f['action']->fresh()->status);
            $this->assertSame($count, DB::table($table)->count());
            $this->assertSame(1, $f['conversation']->messages()->where('tool_calls->version', 2)->count());
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
        }
    }

    #[DataProvider('creationDomains')]
    public function test_expiration_during_slow_domain_write_rolls_back_business_change_and_receipt(string $name, string $table, string $gateOn): void
    {
        $f = $this->fixture($name);
        $count = DB::table($table)->count();
        $this->expireSoon($f);
        [$worker, $gate] = $this->start($f, ['gate_on' => $gateOn]);
        $this->event($worker, 'gate');
        $this->awaitExpiry($f);
        $gate->write("resume\n");
        $result = $this->outcome($worker);
        $this->assertSame(410, $result['status']);
        $this->assertSame('action_expired', $result['body']['code']);
        $this->assertSame($count, DB::table($table)->count());
        $this->assertNull($f['action']->fresh()->receipt);
        [$retry] = $this->start($f);
        $this->assertSame(410, $this->outcome($retry)['status']);
    }

    #[DataProvider('creationDomains')]
    public function test_rollback_of_business_and_receipt_allows_a_waiter_to_execute_once(string $name, string $table, string $gateOn): void
    {
        $f = $this->fixture($name);
        $count = DB::table($table)->count();
        [$one, $gate] = $this->start($f, ['gate_on' => 'action_saved', 'fail_after_write' => true]);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f);
        $this->waiting($connection, 'ai_conversations');
        $this->assertSame($count, DB::table($table)->count());
        $this->assertNull($f['action']->fresh()->receipt);
        $gate->write("resume\n");
        $this->assertSame(500, $this->outcome($one)['status']);
        $this->assertSame(200, $this->outcome($two)['status']);
        $this->assertSame($count + 1, DB::table($table)->count());
        $this->assertSame('executed', $f['action']->fresh()->status);
    }

    #[DataProvider('creationDomains')]
    public function test_committed_action_survives_lost_http_response_and_replays_from_another_process(string $name, string $table, string $gateOn): void
    {
        $f = $this->fixture($name);
        [$one] = $this->start($f, ['gate_on' => 'response_committed']);
        $this->event($one, 'gate');
        $receipt = $f['action']->fresh()->receipt;
        $before = DB::table($table)->orderBy('id')->get()->toArray();
        $one->stop(0, SIGKILL);
        [$retry] = $this->start($f);
        $result = $this->outcome($retry);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['replayed']);
        $this->assertEquals($receipt, $result['body']['data']['receipt']);
        $this->assertEquals($before, DB::table($table)->orderBy('id')->get()->toArray());
    }

    public function test_active_turn_excludes_decisions_while_other_conversations_and_users_progress(): void
    {
        $f = $this->fixture('propose_reminder_create');
        $other = $this->fixture('propose_subtask_create', $f['owner']);
        $foreign = $this->fixture('propose_reminder_create');
        [$chat, $gate] = $this->start($f, ['operation' => 'chat', 'request_id' => (string) Str::uuid(), 'hold_provider' => true]);
        $this->event($chat, 'gate');
        foreach (['confirm', 'reject'] as $operation) {
            [$blocked] = $this->start($f, ['operation' => $operation]);
            $result = $this->outcome($blocked);
            $this->assertSame(409, $result['status']);
            $this->assertSame('conversation_busy', $result['body']['code']);
        }
        foreach ([$other, $foreign] as $independent) {
            [$worker] = $this->start($independent);
            $this->assertSame(200, $this->outcome($worker)['status']);
        }
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($chat)['status']);
        [$worker] = $this->start($f);
        $this->assertSame(200, $this->outcome($worker)['status']);
    }

    public function test_rejection_records_history_during_an_owner_transaction_with_a_real_conversation_timestamp_update(): void
    {
        $f = $this->fixture('propose_reminder_create');
        $before = $f['conversation']->fresh()->updated_at->subMinute();
        DB::table('ai_conversations')->where('id', $f['conversation']->id)->update(['updated_at' => $before]);
        DB::beginTransaction();
        User::whereKey($f['owner']->id)->lockForUpdate()->firstOrFail();
        try {
            [$worker] = $this->start($f, ['operation' => 'reject']);
            $this->assertSame(200, $this->outcome($worker)['status']);
            $this->assertSame('rejected', $f['action']->fresh()->status);
            $this->assertNull($f['action']->fresh()->receipt);
            $this->assertTrue($f['conversation']->fresh()->updated_at->greaterThan($before));
            $this->assertSame(1, $f['conversation']->messages()->where('tool_calls->version', 2)->count());
            $this->assertDatabaseCount('reminders', 1);
        } finally {
            DB::rollBack();
        }
    }

    public function test_expired_chat_worker_cannot_commit_its_prepared_h3b_action(): void
    {
        $f = $this->fixture();
        AiPendingAction::query()->delete();
        [$one, $gate] = $this->start($f, ['operation' => 'chat', 'request_id' => (string) Str::uuid(), 'hold_provider' => true]);
        $prepared = $this->event($one, 'prepared_action_id')['prepared_action_id'];
        $this->event($one, 'gate');
        $this->assertDatabaseCount('ai_pending_actions', 0);
        DB::table('ai_conversation_turns')->whereNull('result')->update(['lease_expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        [$successor] = $this->start($f, ['operation' => 'chat', 'request_id' => (string) Str::uuid()]);
        $this->assertSame(200, $this->outcome($successor)['status']);
        $gate->write("resume\n");
        $result = $this->outcome($one);
        $this->assertSame(409, $result['status']);
        $this->assertSame('turn_expired', $result['body']['code']);
        $this->assertDatabaseCount('ai_pending_actions', 1);
        $this->assertDatabaseMissing('ai_pending_actions', ['id' => $prepared]);
        $this->assertDatabaseCount('reminders', 1);
    }
}
