<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
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
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

#[Group('ai')]
#[Group('mysql')]
class CompactAiAvailabilityActionsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => getenv('AI_TEST_PROVIDER') ?: 'openai', 'ai.openai_key' => 'offline-openai-key', 'ai.model' => 'gpt-5.4-mini', 'ai.gemini_key' => 'offline-gemini-key', 'ai.gemini_model' => 'gemini-offline-fixture', 'ai.requests_per_minute' => 60]);
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
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('Already changed your availability!'), $this->aiCall($name, json_encode($arguments ?? $this->arguments($f, $name), JSON_THROW_ON_ERROR), 'call_'.str_replace('-', '', (string) Str::uuid()))]))->push($this->aiResponse([$this->aiText('Applied successfully!')]));

        return $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one availability change.'], ['Idempotency-Key' => $key ?? (string) Str::uuid()])->assertOk()->json('data');
    }

    private function decide(array $f, string $id, string $operation = 'confirm', array $body = [])
    {
        return $this->call('POST', '/api/ai/conversations/'.$f['conversation']->id.'/actions/'.$id.'/'.$operation, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode((object) $body, JSON_THROW_ON_ERROR));
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
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public static function actions(): array
    {
        return [0 => ['propose_availability_create']];
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

            return $real->{$method}(...$args);
        });
        $this->app->instance(StudyAvailabilityService::class, $writer);
        $before = $this->snapshot();
        $key = (string) Str::uuid();
        $turn = $this->propose($f, $name, key: $key);
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue($turn['tools'][0]['ok']);
        $this->assertSame('confirmation_required', $turn['action']['state']);
        $this->assertStringContainsString('підготовлено до підтвердження', $turn['content']);
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
        $replay = $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one availability change.'], ['Idempotency-Key' => $key])->assertOk()->json('data');
        $this->assertSame($turn, $replay);
        $this->assertDatabaseCount('ai_pending_actions', 1);
        $id = $turn['action']['id'];
        $this->getJson('/api/ai/conversations/'.$f['conversation']->id.'/actions/'.$id)->assertOk()->assertJsonPath('data.type', $name);
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->never())->method('respond');
        $this->app->instance(LlmProvider::class, $provider);
        $receipt = $this->decide($f, $id)->assertOk()->assertJsonPath('data.status', 'executed')->assertJsonPath('replayed', false)->json('data.receipt');
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
        $oldScan = json_decode($before['recovery_scan_states'][0], true);
        $newScan = json_decode($after['recovery_scan_states'][0], true);
        $this->assertSame($oldScan['requested_version'] + 1, $newScan['requested_version']);
        $this->assertSame(array_diff_key($oldScan, array_flip(['requested_version', 'requested_at'])), array_diff_key($newScan, array_flip(['requested_version', 'requested_at'])));
        foreach (array_diff(array_keys($before), ['study_availability_windows', 'recovery_scan_states']) as $table) {
            $this->assertSame($before[$table], $after[$table], $table);
        }
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
        $this->assertSame('Підтверджені зміни виконано.', $event->content);
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

    public static function invalidCandidates(): array
    {
        return [0 => [['day_of_week' => 1, 'starts_at' => '16:30:00', 'ends_at' => '21:00:00']]];
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
}
