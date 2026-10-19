<?php

namespace Tests\Feature;

use App\Models\AiPendingAction;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\Actions\PlanningActionRegistry;
use App\Services\Planning\StudyPlanConfirmationService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningConfirmationService;
use App\Services\Tasks\SubtaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithPlanningActions;
use Tests\TestCase;

class AiPlanningActionConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithPlanningActions;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.openai_key' => 'fake', 'ai.model' => 'gpt-5.4-mini', 'ai.requests_per_minute' => 60]);
        Http::preventStrayRequests();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->workers as [$worker, $stream]) {
                $worker->stop();
                $stream->close();
            }
            $this->truncateTablesForAllConnections();
        });
    }

    private function fixture(string $name = 'propose_study_plan_create', ?User $owner = null): array
    {
        $f = $this->planningFixture($name, $owner);
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        $f['action'] = AiPendingAction::findOrFail($id);

        return $f;
    }

    private function multipleSessions(array $f): array
    {
        if ($f['name'] === 'propose_study_plan_create') {
            Task::factory()->for($f['owner'])->create(['estimated_minutes' => 60]);
        } elseif ($f['name'] === 'propose_adaptive_replanning') {
            StudySession::factory()->for($f['task'])->create(['starts_at' => $f['date']->setHour(11), 'ends_at' => $f['date']->setHour(12)]);
            $f['lesson']->update(['ends_at' => $f['date']->setHour(12)]);
        } else {
            return $f;
        }
        $f['action'] = AiPendingAction::findOrFail($this->proposePlanning($f)->assertOk()->json('data.action.id'));

        return $f;
    }

    private function start(array $f, array $options = []): array
    {
        $stream = new InputStream;
        $config = DB::connection()->getConfig();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/ai_planning_action_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => 'student_planner_testing',
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => $config['unix_socket'] ?? '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], $stream, 20);
        $stream->write(json_encode(['owner_id' => $f['owner']->id, 'conversation_id' => $f['conversation']->id,
            'action_id' => $f['action']->id, 'operation' => 'confirm', 'name' => $f['name'], 'arguments' => $f['arguments'],
            'source_id' => $f['source']?->id, 'task_id' => $f['task']->id, 'window_id' => $f['window']->id,
            'reminder_id' => $f['reminder']?->id, ...$options], JSON_THROW_ON_ERROR)."\n");
        $this->workers[] = [$worker, $stream];
        $worker->start();
        $ready = $this->event($worker, 'ready');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $ready['connection_id']);

        return [$worker, $stream, $ready['connection_id']];
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
        $this->fail('Missing '.$key.' worker event: '.$worker->getErrorOutput().' '.$worker->getOutput());
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
        throw new RuntimeException('Missing worker HTTP response.');
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
        $this->fail('No genuine MySQL contention on '.$table);
    }

    public static function actions(): array
    {
        return array_map(fn ($name) => [$name], PlanningActionRegistry::NAMES);
    }

    public static function orderings(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('actions')]
    public function test_two_confirmations_commit_one_domain_operation_and_replay_the_exact_receipt(string $name): void
    {
        $f = $this->fixture($name);
        [$one, $gate] = $this->start($f, ['gate_on' => 'session_written']);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f);
        $this->waiting($connection, 'ai_conversations');
        $this->assertNull($f['action']->fresh()->receipt);
        $gate->write("resume\n");
        $first = $this->outcome($one);
        $second = $this->outcome($two);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertTrue($second['body']['replayed']);
        $this->assertEquals($first['body']['data']['receipt'], $second['body']['data']['receipt']);
        $this->assertDatabaseCount('study_sessions', $name === 'propose_study_plan_create' ? 1 : 2);
        $this->assertSame(1, $f['conversation']->messages()->where('tool_calls->version', 2)->count());
    }

    #[DataProvider('orderings')]
    public function test_confirmation_and_rejection_serialize_one_terminal_outcome(bool $confirmFirst): void
    {
        $f = $this->fixture();
        [$one, $gate] = $this->start($f, ['operation' => $confirmFirst ? 'confirm' : 'reject', 'gate_on' => 'action_written']);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f, ['operation' => $confirmFirst ? 'reject' : 'confirm']);
        $this->waiting($connection, 'ai_conversations');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($one)['status']);
        $this->assertSame(409, $this->outcome($two)['status']);
        $this->assertSame($confirmFirst ? 'executed' : 'rejected', $f['action']->fresh()->status);
        $this->assertDatabaseCount('study_sessions', $confirmFirst ? 1 : 0);
    }

    public static function directRaces(): array
    {
        $cases = [];
        foreach ([['propose_study_plan_create', 'direct_plan'], ['propose_adaptive_replanning', 'cancel_session'],
            ['propose_session_reschedule', 'direct_reschedule']] as [$name, $operation]) {
            $cases[] = [$name, $operation, true];
            $cases[] = [$name, $operation, false];
        }

        return $cases;
    }

    #[DataProvider('directRaces')]
    public function test_ai_and_direct_rest_confirmation_or_lifecycle_serialize_current_state(string $name, string $operation, bool $aiFirst): void
    {
        $f = $this->fixture($name);
        $body = $operation === 'direct_plan' ? ['preview_token' => app(StudyPlanPersistenceService::class)->preview($f['owner'], ...array_values($f['arguments']))['preview_token']]
            : array_intersect_key($f['arguments'], ['starts_at' => true, 'ends_at' => true]);
        [$one, $gate] = $this->start($f, ['operation' => $aiFirst ? 'confirm' : $operation, 'body' => $body, 'gate_on' => 'session_written']);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f, ['operation' => $aiFirst ? $operation : 'confirm', 'body' => $body]);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertContains($this->outcome($one)['status'], [200, 201]);
        $second = $this->outcome($two);
        $this->assertSame(409, $second['status']);
        if (! $aiFirst) {
            $this->assertSame('action_stale', $second['body']['code']);
        }
        $this->assertSame($aiFirst ? 'executed' : 'pending', $f['action']->fresh()->status);
        if ($f['source'] !== null) {
            $this->assertSame($operation === 'cancel_session' && ! $aiFirst ? 0 : 1,
                StudySession::where('rescheduled_from_session_id', $f['source']->id)->count());
        }
    }

    public static function contextChanges(): array
    {
        return [['propose_study_plan_create', 'availability'], ['propose_study_plan_create', 'deadline'],
            ['propose_study_plan_create', 'subtask_deadline'], ['propose_adaptive_replanning', 'preferences']];
    }

    #[DataProvider('contextChanges')]
    public function test_owner_wait_discovers_current_context_after_real_rest_changes_commit(string $name, string $operation): void
    {
        $f = $this->planningFixture($name);
        $body = match ($operation) {
            'availability' => ['ends_at' => '10:00:00'],
            'deadline', 'subtask_deadline' => ['deadline_at' => $f['date']->setHour(9)->format('Y-m-d\TH:i:s\Z')],
            'preferences' => ['min_session_minutes' => null, 'max_session_minutes' => null, 'preferred_break_minutes' => null,
                'max_daily_study_minutes' => 30, 'max_weekly_study_minutes' => null],
        };
        if ($operation === 'subtask_deadline') {
            $f['child'] = Subtask::factory()->for($f['task'])->create(['estimated_minutes' => 240, 'deadline_at' => $f['date']->setHour(23)]);
            $f['arguments'] += ['task_id' => $f['task']->id, 'subtask_id' => $f['child']->id];
        }
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        $f['action'] = AiPendingAction::findOrFail($id);
        $gateOn = match ($operation) {
            'availability' => 'availability_written', 'preferences' => 'preferences_written', default => 'task_written'
        };
        if ($operation === 'subtask_deadline') {
            // Hold owner in the parent, commit a current child deadline while the worker waits.
            DB::beginTransaction();
            User::whereKey($f['owner']->id)->lockForUpdate()->firstOrFail();
            try {
                [$worker, , $connection] = $this->start($f);
                $this->waiting($connection, 'users');
                app(SubtaskService::class)->update($f['owner'], $f['task'], $f['child'], $body);
                DB::commit();
                $this->assertSame(409, $this->outcome($worker)['status']);
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        } else {
            [$one, $gate] = $this->start($f, ['operation' => $operation, 'body' => $body, 'gate_on' => $gateOn]);
            $this->event($one, 'gate');
            [$two, , $connection] = $this->start($f);
            $this->waiting($connection, 'users');
            $gate->write("resume\n");
            $this->assertSame(200, $this->outcome($one)['status']);
            $this->assertSame(409, $this->outcome($two)['status']);
        }
        $this->assertSame('pending', $f['action']->fresh()->status);
        $this->assertNull($f['action']->fresh()->receipt);
    }

    public static function reminderRaces(): array
    {
        return [['delivery', true], ['delivery', false], ['reminder_update', true], ['reminder_update', false]];
    }

    #[DataProvider('reminderRaces')]
    public function test_manual_rescheduling_preserves_delivery_and_scheduled_reminder_race_semantics(string $operation, bool $aiFirst): void
    {
        $f = $this->fixture('propose_session_reschedule');
        $options = ['body' => ['message' => 'Concurrent reminder edit']];
        if ($operation === 'delivery') {
            $options['now'] = $f['date']->setHour(9)->addMinutes(30)->toISOString();
        }
        [$one, $gate] = $this->start($f, ['operation' => $aiFirst ? 'confirm' : $operation,
            'gate_on' => $aiFirst ? 'session_written' : 'reminder_written', ...($aiFirst ? [] : $options)]);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f, ['operation' => $aiFirst ? $operation : 'confirm', ...($aiFirst ? $options : [])]);
        $this->waiting($connection, 'users');
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($one)['status']);
        $this->assertSame($operation === 'reminder_update' && $aiFirst ? 409 : 200, $this->outcome($two)['status']);
        $this->assertSame('executed', $f['action']->fresh()->status);
        $this->assertDatabaseCount('study_sessions', 2);
        $sent = $operation === 'delivery' && ! $aiFirst;
        $this->assertSame($sent ? 'sent' : 'cancelled', $f['reminder']->fresh()->status->value);
        $this->assertDatabaseCount('notifications', $sent ? 1 : 0);
    }

    private function expireSoon(array $f): void
    {
        DB::table('ai_pending_actions')->where('id', $f['action']->id)->update(['expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 2 SECOND)')]);
    }

    private function awaitExpiry(array $f): void
    {
        $deadline = microtime(true) + 4;
        do {
            $elapsed = DB::selectOne('SELECT expires_at <= UTC_TIMESTAMP(6) AS elapsed FROM ai_pending_actions WHERE id = ?', [$f['action']->id])->elapsed;
            if ($elapsed) {
                $this->assertTrue((bool) $elapsed);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Expected MySQL expiry.');
    }

    public static function expiryLocks(): array
    {
        $cases = [];
        foreach (PlanningActionRegistry::NAMES as $name) {
            foreach (['users', 'tasks'] as $table) {
                $cases[] = [$name, $table];
            }
        }

        return $cases;
    }

    #[DataProvider('expiryLocks')]
    public function test_expiry_during_real_owner_or_domain_contention_never_commits_a_mutation(string $name, string $table): void
    {
        $f = $this->fixture($name);
        $before = $this->planningSnapshot();
        $this->expireSoon($f);
        DB::beginTransaction();
        ($table === 'users' ? User::whereKey($f['owner']->id) : Task::whereKey($f['task']->id))->lockForUpdate()->firstOrFail();
        try {
            [$worker, , $connection] = $this->start($f);
            $this->waiting($connection, $table);
            $this->awaitExpiry($f);
            DB::commit();
            $this->assertSame(410, $this->outcome($worker)['status']);
            $this->assertSame($before, $this->planningSnapshot());
            $this->assertNull($f['action']->fresh()->receipt);
            [$retry] = $this->start($f);
            $this->assertSame(410, $this->outcome($retry)['status']);
            $this->assertSame('expired', $f['action']->fresh()->status);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    #[DataProvider('actions')]
    public function test_expiration_during_a_slow_write_rolls_back_the_entire_domain_batch(string $name): void
    {
        $f = $this->fixture($name);
        $f = $this->multipleSessions($f);
        $before = $this->planningSnapshot();
        $this->expireSoon($f);
        [$worker, $gate] = $this->start($f, ['gate_on' => 'session_written']);
        $this->event($worker, 'gate');
        $this->awaitExpiry($f);
        $gate->write("resume\n");
        $this->assertSame(410, $this->outcome($worker)['status']);
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertNull($f['action']->fresh()->receipt);
    }

    #[DataProvider('actions')]
    public function test_failure_after_business_and_receipt_write_rolls_back_and_waiter_can_execute_once(string $name): void
    {
        $f = $this->fixture($name);
        $f = $this->multipleSessions($f);
        $before = $this->planningSnapshot();
        [$one, $gate] = $this->start($f, ['gate_on' => 'action_written', 'fail_after_write' => true]);
        $this->event($one, 'gate');
        [$two, , $connection] = $this->start($f);
        $this->waiting($connection, 'ai_conversations');
        $this->assertSame($before, $this->planningSnapshot());
        $gate->write("resume\n");
        $this->assertSame(500, $this->outcome($one)['status']);
        $this->assertSame(200, $this->outcome($two)['status']);
        $this->assertSame('executed', $f['action']->fresh()->status);
        if ($f['source'] !== null) {
            $this->assertSame(1, StudySession::where('rescheduled_from_session_id', $f['source']->id)->count());
        }
    }

    #[DataProvider('actions')]
    public function test_lost_response_after_commit_replays_from_another_process_without_new_rows(string $name): void
    {
        $f = $this->fixture($name);
        [$one] = $this->start($f, ['gate_on' => 'response_committed']);
        $this->event($one, 'gate');
        $receipt = $f['action']->fresh()->receipt;
        $before = $this->planningSnapshot();
        $one->stop(0, SIGKILL);
        [$retry] = $this->start($f);
        $outcome = $this->outcome($retry);
        $this->assertSame(200, $outcome['status']);
        $this->assertTrue($outcome['body']['replayed']);
        $this->assertEquals($receipt, $outcome['body']['data']['receipt']);
        $this->assertSame($before, $this->planningSnapshot());
    }

    public function test_expired_h2_worker_cannot_persist_its_in_memory_planning_token_or_action(): void
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
        $this->assertSame(409, $this->outcome($one)['status']);
        $this->assertDatabaseMissing('ai_pending_actions', ['id' => $prepared]);
        $this->assertDatabaseCount('ai_pending_actions', 1);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_active_turn_exclusion_and_independent_owners_make_progress_without_global_locks(): void
    {
        $f = $this->fixture();
        $other = $this->fixture('propose_session_reschedule');
        [$chat, $gate] = $this->start($f, ['operation' => 'chat', 'request_id' => (string) Str::uuid(), 'hold_provider' => true]);
        $this->event($chat, 'gate');
        [$blocked] = $this->start($f);
        $this->assertSame('conversation_busy', $this->outcome($blocked)['body']['code']);
        [$independent] = $this->start($other);
        $this->assertSame(200, $this->outcome($independent)['status']);
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($chat)['status']);
        [$confirmed] = $this->start($f);
        $this->assertSame(200, $this->outcome($confirmed)['status']);
    }

    public static function tokenDomains(): array
    {
        return [['propose_study_plan_create'], ['propose_adaptive_replanning']];
    }

    #[DataProvider('tokenDomains')]
    public function test_domain_token_expiration_during_slow_multi_session_execution_rolls_back_all_effects(string $name): void
    {
        $f = $this->multipleSessions($this->fixture($name));
        $confirmation = app($name === 'propose_study_plan_create'
            ? StudyPlanConfirmationService::class : StudyReplanningConfirmationService::class);
        $private = $f['action']->protected_payload;
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $payload = $confirmation->decode($private['preview_token'], $f['owner']->id, $now);
        $issued = $now - 898;
        $private['preview_token'] = $confirmation->issue($f['owner']->id, $payload['parameters'], $payload['plan'] ?? $payload['review'], $issued);
        $private['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', $issued + 900);
        $f['action']->update(['protected_payload' => $private, 'expires_at' => CarbonImmutable::createFromTimestampUTC($issued + 900)]);
        $before = $this->planningSnapshot();
        [$worker, $gate] = $this->start($f, ['gate_on' => 'session_written']);
        $this->event($worker, 'gate');
        $this->awaitExpiry($f);
        $gate->write("resume\n");
        $this->assertSame(410, $this->outcome($worker)['status']);
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertNull($f['action']->fresh()->receipt);
        [$retry] = $this->start($f);
        $this->assertSame(410, $this->outcome($retry)['status']);
    }

    #[DataProvider('actions')]
    public function test_independent_owners_confirm_while_another_planning_transaction_holds_its_locks(string $name): void
    {
        $oneFixture = $this->fixture($name);
        $otherFixture = $this->fixture($name);
        [$one, $gate] = $this->start($oneFixture, ['gate_on' => 'session_written']);
        $this->event($one, 'gate');
        [$other] = $this->start($otherFixture);
        $this->assertSame(200, $this->outcome($other)['status']);
        $this->assertNull($oneFixture['action']->fresh()->receipt);
        $this->assertSame('executed', $otherFixture['action']->fresh()->status);
        $gate->write("resume\n");
        $this->assertSame(200, $this->outcome($one)['status']);
        $this->assertSame('executed', $oneFixture['action']->fresh()->status);
    }
}
