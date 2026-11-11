<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\Actions\ActionTargetFingerprint;
use App\Services\AI\Actions\AvailabilityActionRegistry;
use App\Services\AI\Actions\AvailabilityActionService;
use App\Services\AI\LlmProvider;
use App\Services\Planning\StudyAvailabilityService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\Support\AiCapabilities;
use Tests\TestCase;

#[Group('mysql')]
#[Group('ai')]
class AiAvailabilityActionsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => getenv('AI_TEST_PROVIDER') ?: 'openai', 'ai.openai_key' => 'offline-openai-key',
            'ai.model' => 'gpt-5.4-mini', 'ai.gemini_key' => 'offline-gemini-key', 'ai.gemini_model' => 'gemini-offline-fixture', 'ai.requests_per_minute' => 60]);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $window = StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '16:00:00', 'ends_at' => '20:00:00']);
        $task = Task::factory()->for($owner)->create();
        StudySession::factory()->for($task)->create();
        Reminder::factory()->for($owner)->create(['task_id' => $task->id]);
        Lesson::factory()->for($owner)->create();
        PlanningPreference::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');

        return compact('owner', 'conversation', 'window');
    }

    private function arguments(array $f, string $name): array
    {
        return match ($name) {
            'propose_availability_create' => ['day_of_week' => 1, 'starts_at' => '20:00:00', 'ends_at' => '24:00:00'],
            'propose_availability_update' => ['availability_window_id' => $f['window']->id, 'starts_at' => '17:00:01'],
            'propose_availability_delete' => ['availability_window_id' => $f['window']->id],
        };
    }

    private function propose(array $f, string $name, ?array $arguments = null, ?string $key = null): array
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('Already changed your availability!'),
            $this->aiCall($name, json_encode($arguments ?? $this->arguments($f, $name), JSON_THROW_ON_ERROR), 'call_'.str_replace('-', '', (string) Str::uuid()))]))
            ->push($this->aiResponse([$this->aiText('Applied successfully!')]));

        return $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one availability change.'],
            ['Idempotency-Key' => $key ?? (string) Str::uuid()])->assertOk()->json('data');
    }

    private function decide(array $f, string $id, string $operation = 'confirm', array $body = [])
    {
        return $this->call('POST', '/api/ai/conversations/'.$f['conversation']->id.'/actions/'.$id.'/'.$operation, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    private function snapshot(bool $includeAi = false): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (! $includeAi && in_array($table, ['ai_conversations', 'ai_conversation_turns', 'ai_messages', 'ai_pending_actions'], true)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $snapshot[$table] = $rows;
        }
        ksort($snapshot);

        return $snapshot;
    }

    private function assertWindow(array $expected, array $actual): void
    {
        // MySQL JSON object key order is not a wire contract; keep strict scalar equality.
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public static function actions(): array
    {
        return array_map(fn ($name) => [$name], AvailabilityActionRegistry::NAMES);
    }

    #[DataProvider('actions')]
    public function test_each_preparation_is_select_only_with_no_pending_actions_or_external_effects(string $name): void
    {
        $f = $this->fixture();
        $f['owner']->timezone = 'UTC';
        $before = $this->snapshot(true);
        Bus::fake();
        Queue::fake();
        Notification::fake();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $prepared = app(AvailabilityActionService::class)->prepare($f['owner'], $name, $this->arguments($f, $name), 'call_1');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertTrue($prepared['result']['ok']);
        $this->assertSame('Europe/Kyiv', $prepared['proposal']['preview']['timezone']);
        $this->assertSame('UTC', $f['owner']->timezone);
        $this->assertSame($before, $this->snapshot(true));
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertSame(64, strlen($prepared['proposal']['target_hash']));
        $this->assertStringNotContainsString('target_hash', json_encode($prepared['result']));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    #[DataProvider('actions')]
    public function test_explicit_confirmation_delegates_once_to_existing_service_and_replays_immutable_receipt(string $name): void
    {
        $f = $this->fixture();
        $method = substr($name, strlen('propose_availability_'));
        $real = new StudyAvailabilityService;
        $writer = $this->createPartialMock(StudyAvailabilityService::class, ['create', 'update', 'delete']);
        foreach (['create', 'update', 'delete'] as $other) {
            if ($other !== $method) {
                $writer->expects($this->never())->method($other);
            }
        }
        $writer->expects($this->once())->method($method)->willReturnCallback(function (...$args) use ($real, $method) {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->assertSame('pending', AiPendingAction::sole()->status);

            return $real->$method(...$args);
        });
        $this->app->instance(StudyAvailabilityService::class, $writer);
        $before = $this->snapshot();
        $key = (string) Str::uuid();
        $turn = $this->propose($f, $name, key: $key);
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue($turn['tools'][0]['ok']);
        $this->assertSame('confirmation_required', $turn['action']['state']);
        $this->assertStringContainsString('Confirmation is required', $turn['content']);
        $this->assertStringNotContainsString('target_hash', json_encode($turn));
        $preview = $turn['action']['preview'];
        $this->assertSame($name, $preview['type']);
        $this->assertSame('Europe/Kyiv', $preview['timezone']);
        if ($method === 'create') {
            $this->assertNull($preview['before']);
            $this->assertNull($preview['after']['id']);
            $this->assertSame('24:00:00', $preview['after']['ends_at']);
        } elseif ($method === 'update') {
            $this->assertSame($f['window']->id, $preview['before']['id']);
            $this->assertSame(1, $preview['after']['day_of_week']);
            $this->assertSame('20:00:00', $preview['after']['ends_at']);
        } else {
            $this->assertWindow($f['window']->only(['id', 'day_of_week', 'starts_at', 'ends_at']), $preview['before']);
            $this->assertNull($preview['after']);
        }
        $replay = $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one availability change.'],
            ['Idempotency-Key' => $key])->assertOk()->json('data');
        $this->assertSame($turn, $replay);
        $this->assertDatabaseCount('ai_pending_actions', 1);
        $id = $turn['action']['id'];
        $this->getJson('/api/ai/conversations/'.$f['conversation']->id.'/actions/'.$id)->assertOk()->assertJsonPath('data.type', $name);
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->never())->method('respond');
        $this->app->instance(LlmProvider::class, $provider);
        $receipt = $this->decide($f, $id)->assertOk()->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('replayed', false)->json('data.receipt');
        $this->assertSame($id, $receipt['action_id']);
        $this->assertSame($name, $receipt['type']);
        $this->assertSame($preview['effects'], $receipt['effects']);
        $this->assertSame('Europe/Kyiv', $receipt['timezone']);
        if ($method === 'delete') {
            $this->assertNull($receipt['window']);
            $this->assertSame($preview['before'], $receipt['deleted_window']);
            $this->assertDatabaseMissing('study_availability_windows', ['id' => $f['window']->id]);
        } else {
            $this->assertWindow(StudyAvailabilityWindow::findOrFail($receipt['window']['id'])->only(['id', 'day_of_week', 'starts_at', 'ends_at']), $receipt['window']);
            $this->assertNull($receipt['deleted_window']);
        }
        $after = $this->snapshot();
        foreach (array_diff(array_keys($before), ['study_availability_windows']) as $table) {
            $this->assertSame($before[$table], $after[$table], $table);
        }
        // Alter current state after success; replay must still return the original durable receipt.
        if ($receipt['window'] !== null) {
            $real->delete($f['owner'], StudyAvailabilityWindow::findOrFail($receipt['window']['id']));
        } else {
            $real->create($f['owner'], $this->arguments($f, 'propose_availability_create'));
        }
        $changed = $this->snapshot();
        $this->decide($f, $id)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.receipt', $receipt);
        $this->assertSame($changed, $this->snapshot());
        $this->decide($f, $id, 'reject')->assertConflict()->assertJsonPath('code', 'action_executed');
        $event = $f['conversation']->messages()->where('tool_calls->version', 2)->sole();
        $this->assertSame($receipt, $event->tool_calls['action_event']['receipt']);
        $this->assertSame('Availability action executed.', $event->content);
        $this->assertStringNotContainsString('Already changed', json_encode($f['conversation']->messages()->pluck('content')));
        $publicHistory = $this->getJson('/api/ai/conversations/'.$f['conversation']->id.'/messages')->assertOk();
        $this->assertStringNotContainsString('Already changed', $publicHistory->getContent());
        $this->assertStringNotContainsString('gemini-offline-fixture', $publicHistory->getContent());
        Http::assertSentCount(2);
    }

    #[DataProvider('actions')]
    public function test_rejection_expiry_and_planning_consent_rejection_preserve_business_data(string $name): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $turn = $this->propose($f, $name);
        $id = $turn['action']['id'];
        foreach (['accept_partial', 'accept_unplaced_cancellation', 'user_id', 'starts_at'] as $field) {
            $this->decide($f, $id, body: [$field => true])->assertUnprocessable();
        }
        $this->decide($f, $id, 'reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->decide($f, $id, 'reject')->assertOk();
        $this->decide($f, $id)->assertConflict();
        $turn = $this->propose($f, $name);
        $id = $turn['action']['id'];
        DB::table('ai_pending_actions')->where('id', $id)->update(['expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        $this->decide($f, $id)->assertGone()->assertJsonPath('data.status', 'expired');
        $this->decide($f, $id)->assertGone();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, AiPendingAction::whereNotNull('receipt')->count());
    }

    public function test_foreign_and_missing_ids_have_identical_safe_errors_and_no_dispatch(): void
    {
        $f = $this->fixture();
        $foreign = StudyAvailabilityWindow::factory()->create();
        foreach (['propose_availability_update', 'propose_availability_delete'] as $name) {
            $results = [];
            foreach ([$foreign->id, PHP_INT_MAX] as $id) {
                $arguments = ['availability_window_id' => $id, ...($name === 'propose_availability_update' ? ['ends_at' => '24:00:00'] : [])];
                $results[] = app(AvailabilityActionService::class)->prepare($f['owner'], $name, $arguments, 'call_1')['result'];
            }
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(['code' => 'not_found', 'message' => 'Resource not found.'], $results[0]['error']);
        }
        $this->assertSame('access_denied', app(AvailabilityActionService::class)->prepare(new User,
            'propose_availability_create', $this->arguments($f, 'propose_availability_create'), 'call_1')['result']['error']['code']);
        $turn = $this->propose($f, 'propose_availability_delete', ['availability_window_id' => $foreign->id]);
        $this->assertFalse($turn['tools'][0]['ok']);
        $this->assertArrayNotHasKey('action', $turn);
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertDatabaseHas('study_availability_windows', ['id' => $foreign->id]);
    }

    public static function invalidCandidates(): array
    {
        return [
            [['day_of_week' => 1, 'starts_at' => '16:30:00', 'ends_at' => '21:00:00']],
            [['day_of_week' => 1, 'starts_at' => '21:00:00', 'ends_at' => '20:00:00']],
            [['day_of_week' => 1, 'starts_at' => '21:00:00', 'ends_at' => '21:00:00']],
            [['day_of_week' => 1, 'starts_at' => '23:00:00', 'ends_at' => '01:00:00']],
            [['day_of_week' => 8, 'starts_at' => '21:00:00', 'ends_at' => '24:00:00']],
            [['day_of_week' => '1', 'starts_at' => '21:00:00', 'ends_at' => '24:00:00']],
            [['day_of_week' => 1, 'starts_at' => '24:00:00', 'ends_at' => '24:00:00']],
            [['day_of_week' => 1, 'starts_at' => '21:00:00', 'ends_at' => '24:00:01']],
            [['day_of_week' => 1, 'starts_at' => '21:00', 'ends_at' => '24:00:00']],
        ];
    }

    #[DataProvider('invalidCandidates')]
    public function test_service_and_ai_share_complete_candidate_validation_without_writes(array $values): void
    {
        $f = $this->fixture();
        $before = $this->snapshot(true);
        try {
            app(StudyAvailabilityService::class)->prepare($f['owner'], $values);
            $this->fail('Invalid preflight accepted.');
        } catch (ValidationException) {
        }
        $prepared = app(AvailabilityActionService::class)->prepare($f['owner'], 'propose_availability_create', $values, 'call_1');
        $this->assertSame('invalid_arguments', $prepared['result']['error']['code']);
        $this->assertSame($before, $this->snapshot(true));
    }

    public function test_partial_update_revalidates_the_complete_interval_and_self_exclusion(): void
    {
        $f = $this->fixture();
        $service = app(AvailabilityActionService::class);
        foreach ([['starts_at' => '20:00:00'], ['ends_at' => '15:00:00']] as $values) {
            $result = $service->prepare($f['owner'], 'propose_availability_update', ['availability_window_id' => $f['window']->id, ...$values], 'call_1');
            $this->assertSame('invalid_arguments', $result['result']['error']['code']);
        }
        $valid = $service->prepare($f['owner'], 'propose_availability_update', ['availability_window_id' => $f['window']->id, 'ends_at' => '24:00:00'], 'call_1');
        $this->assertTrue($valid['result']['ok']);
        $this->assertSame('16:00:00', $valid['proposal']['preview']['after']['starts_at']);
        $this->assertSame('24:00:00', $valid['proposal']['preview']['after']['ends_at']);
    }

    public static function staleChanges(): iterable
    {
        foreach (AvailabilityActionRegistry::NAMES as $name) {
            foreach (['target', 'target_deleted', 'sibling', 'timezone'] as $change) {
                yield [$name, $change];
            }
        }
    }

    #[DataProvider('staleChanges')]
    public function test_changed_configuration_or_timezone_requires_a_new_proposal(string $name, string $change): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, $name);
        $real = app(StudyAvailabilityService::class);
        match ($change) {
            'target' => $real->update($f['owner'], $f['window'], ['starts_at' => '16:00:01']),
            'target_deleted' => $real->delete($f['owner'], $f['window']),
            'sibling' => $real->create($f['owner'], ['day_of_week' => 7, 'starts_at' => '00:00:00', 'ends_at' => '01:00:00']),
            'timezone' => $f['owner']->update(['timezone' => 'America/New_York']),
        };
        $before = $this->snapshot(true);
        $this->decide($f, $turn['action']['id'])->assertConflict()->assertJsonPath('code', 'action_stale');
        $this->assertSame($before, $this->snapshot(true));
    }

    public function test_material_review_is_compared_and_unrelated_profile_or_foreign_configuration_does_not_stale(): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, 'propose_availability_update');
        $action = AiPendingAction::sole();
        $preview = $action->preview;
        $action->update(['preview' => array_replace($preview, ['timezone' => 'UTC'])]);
        $this->decide($f, $turn['action']['id'])->assertConflict()->assertJsonPath('code', 'action_stale');
        $action->update(['preview' => $preview]);
        $f['owner']->update(['name' => 'Updated unrelated profile label']);
        StudyAvailabilityWindow::factory()->create();
        $this->decide($f, $turn['action']['id'])->assertOk();
    }

    public function test_confirmation_revalidates_domain_even_if_the_internal_fingerprint_is_rebound(): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, 'propose_availability_create');
        $new = app(StudyAvailabilityService::class)->create($f['owner'], ['day_of_week' => 1, 'starts_at' => '21:00:00', 'ends_at' => '22:00:00']);
        $records = ['owner' => $f['owner']->newQuery()->findOrFail($f['owner']->id, ['id', 'timezone'])];
        foreach ($f['owner']->studyAvailabilityWindows()->orderBy('id')->get() as $window) {
            $records['window:'.$window->id] = $window;
        }
        AiPendingAction::sole()->update(['target_hash' => app(ActionTargetFingerprint::class)->hash($records)]);
        $before = $this->snapshot();
        $this->decide($f, $turn['action']['id'])->assertUnprocessable()->assertJsonPath('code', 'action_validation_failed');
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseHas('study_availability_windows', ['id' => $new->id]);
        $this->assertSame('pending', AiPendingAction::sole()->status);
    }

    public static function failures(): iterable
    {
        foreach (AvailabilityActionRegistry::NAMES as $name) {
            foreach (['cancelled_write', 'after_write', 'receipt', 'history'] as $failure) {
                yield [$name, $failure];
            }
        }
    }

    #[DataProvider('failures')]
    public function test_cancelled_or_failed_writes_receipts_and_history_roll_back_atomically(string $name, string $failure): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, $name);
        $before = $this->snapshot(true);
        $verb = match ($name) {
            'propose_availability_create' => $failure === 'cancelled_write' ? 'creating' : 'created',
            'propose_availability_update' => $failure === 'cancelled_write' ? 'updating' : 'updated',
            default => $failure === 'cancelled_write' ? 'deleting' : 'deleted',
        };
        $event = match ($failure) {
            'receipt' => 'eloquent.saving: '.AiPendingAction::class,
            'history' => 'eloquent.creating: '.AiMessage::class,
            default => 'eloquent.'.$verb.': '.StudyAvailabilityWindow::class,
        };
        $this->app['events']->listen($event, $failure === 'after_write'
            ? function () {
                throw new RuntimeException('Injected private write failure.');
            } : fn () => false);
        try {
            $result = $this->decide($f, $turn['action']['id'])->assertStatus(500)->assertJsonPath('code', 'persistence_error');
            $this->assertStringNotContainsString('Injected private', $result->getContent());
        } finally {
            $this->app['events']->forget($event);
        }
        $this->assertSame($before, $this->snapshot(true));
        $this->assertSame('pending', AiPendingAction::sole()->status);
        $this->assertNull(AiPendingAction::sole()->receipt);
        $this->decide($f, $turn['action']['id'])->assertOk();
    }

    public function test_failed_provider_turn_does_not_persist_proposals(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall('propose_availability_create', json_encode($this->arguments($f, 'propose_availability_create')))]))->push([], 500);
        $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one window.'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(502);
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_conversational_yes_is_not_confirmation_and_other_users_cannot_decide(): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, 'propose_availability_delete');
        $before = $this->snapshot();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('Use the explicit confirmation control.')]));
        $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'yes'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('pending', AiPendingAction::sole()->status);
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->decide($f, $turn['action']['id'])->assertNotFound();
        $foreignConversation = AiConversation::factory()->for(auth()->user())->create();
        $this->decide([...$f, 'conversation' => $foreignConversation], $turn['action']['id'])->assertNotFound();
        auth()->forgetGuards();
        $this->decide($f, $turn['action']['id'])->assertUnauthorized();
    }

    public function test_finalization_failure_does_not_publish_a_confirmable_action_or_transcript(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall('propose_availability_create', json_encode($this->arguments($f, 'propose_availability_create')))]))
            ->push($this->aiResponse([$this->aiText()]));
        $event = 'eloquent.creating: '.AiPendingAction::class;
        $this->app['events']->listen($event, fn () => false);
        try {
            $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one window.'],
                ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(500)->assertJsonPath('code', 'persistence_error');
        } finally {
            $this->app['events']->forget($event);
        }
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_in_memory_writer_result_cannot_replace_persistence_verification(): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, 'propose_availability_update');
        $writer = $this->createPartialMock(StudyAvailabilityService::class, ['update']);
        $writer->expects($this->once())->method('update')->willReturnCallback(function ($owner, $target, $values) {
            $target->fill($values);

            return $target;
        });
        $this->app->instance(StudyAvailabilityService::class, $writer);
        $before = $this->snapshot(true);
        $this->decide($f, $turn['action']['id'])->assertStatus(500)->assertJsonPath('code', 'persistence_error');
        $this->assertSame($before, $this->snapshot(true));
    }

    public function test_owned_read_can_feed_a_later_availability_proposal_in_the_same_turn(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $round = 0;
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->exactly(3))->method('respond')->willReturnCallback(function ($instructions, $history, $definitions) use (&$round) {
            $this->assertSame(AiCapabilities::names(), array_column($definitions, 'name'));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('ai_pending_actions', 0);
            $this->assertStringContainsString('Use list_study_availability_windows to discover exact owned window IDs', $instructions);
            $this->assertStringContainsString('Changing recurring availability does not automatically reschedule existing sessions.', $instructions);
            if (++$round === 1) {
                return ['text' => null, 'calls' => [['id' => 'read_windows', 'name' => 'list_study_availability_windows', 'arguments' => ['day_of_week' => 1]]], 'state' => []];
            }
            if ($round === 2) {
                $read = json_decode($history[array_key_last($history)]['content'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($read['ok']);

                return ['text' => null, 'calls' => [['id' => 'propose_window', 'name' => 'propose_availability_update',
                    'arguments' => ['availability_window_id' => $read['data']['windows'][0]['id'], 'ends_at' => '24:00:00']]], 'state' => []];
            }

            return ['text' => 'Already changed it!', 'calls' => [], 'state' => []];
        });
        $this->app->instance(LlmProvider::class, $provider);
        $turn = $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Extend my Monday window to the end of the day.'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
        $this->assertSame(['list_study_availability_windows', 'propose_availability_update'], array_column($turn['tools'], 'name'));
        $this->assertSame($f['window']->id, $turn['action']['preview']['after']['id']);
        $this->assertSame('24:00:00', $turn['action']['preview']['after']['ends_at']);
        $this->assertStringContainsString('Confirmation is required', $turn['content']);
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_cancelling_a_no_op_update_event_cannot_produce_a_success_receipt(): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, 'propose_availability_update', ['availability_window_id' => $f['window']->id, 'starts_at' => '16:00:00']);
        $before = $this->snapshot(true);
        $event = 'eloquent.saving: '.StudyAvailabilityWindow::class;
        $this->app['events']->listen($event, fn () => false);
        try {
            $this->decide($f, $turn['action']['id'])->assertStatus(500)->assertJsonPath('code', 'persistence_error');
        } finally {
            $this->app['events']->forget($event);
        }
        $this->assertSame($before, $this->snapshot(true));
    }

    public function test_active_turn_blocks_confirmation_without_business_changes(): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, 'propose_availability_delete');
        DB::table('ai_conversation_turns')->insert(['conversation_id' => $f['conversation']->id, 'request_id' => (string) Str::uuid(),
            'input_hash' => hash('sha256', 'active'), 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE)')]);
        $before = $this->snapshot(true);
        $this->decide($f, $turn['action']['id'])->assertConflict()->assertJsonPath('code', 'conversation_busy');
        $this->assertSame($before, $this->snapshot(true));
    }
}
