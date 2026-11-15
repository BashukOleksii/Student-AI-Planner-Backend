<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\LlmProvider;
use App\Services\Reminders\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

#[Group('ai')]
#[Group('mysql')]
class CompactAiSubtaskReminderActionsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => getenv('AI_TEST_PROVIDER') ?: 'openai', 'ai.gemini_key' => 'offline-gemini-test-key', 'ai.gemini_model' => 'gemini-offline-contract-fixture', 'ai.openai_key' => 'fake-test-key', 'ai.model' => 'gpt-5.4-mini', 'ai.requests_per_minute' => 60]);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $task = Task::factory()->for($owner)->create(['deadline_at' => now('UTC')->addDays(7)->startOfSecond()]);
        $child = Subtask::factory()->for($task)->create(['deadline_at' => now('UTC')->addDays(6)->startOfSecond(), 'description' => 'Preserve me']);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => now('UTC')->addDays(3)->startOfSecond(), 'ends_at' => now('UTC')->addDays(3)->addHour()->startOfSecond()]);
        $reminder = app(ReminderService::class)->create($owner, ['message' => 'Original', 'trigger_at' => $this->time(2)]);
        $this->actingAs($owner, 'web');

        return compact('owner', 'conversation', 'task', 'child', 'session', 'reminder');
    }

    private function time(int $days = 1): string
    {
        return now('UTC')->addDays($days)->startOfSecond()->format('Y-m-d\TH:i:s\Z');
    }

    private function url(array $f, ?string $id = null, string $operation = ''): string
    {
        return '/api/ai/conversations/'.$f['conversation']->id.'/actions'.($id === null ? '' : '/'.$id).$operation;
    }

    private function decide(array $f, string $id, string $operation = 'confirm')
    {
        return $this->call('POST', $this->url($f, $id, '/'.$operation), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}');
    }

    private function args(array $f, string $name): array
    {
        return match ($name) {
            'propose_subtask_create' => ['task_id' => $f['task']->id, 'title' => 'New exercise'],
            'propose_subtask_update' => ['task_id' => $f['task']->id, 'subtask_id' => $f['child']->id, 'description' => null],
            'propose_subtask_complete', 'propose_subtask_reopen' => ['task_id' => $f['task']->id, 'subtask_id' => $f['child']->id],
            'propose_reminder_create' => ['message' => 'Review lecture notes', 'trigger_at' => $this->time()],
            'propose_reminder_update' => ['reminder_id' => $f['reminder']->id, 'message' => 'Edited reminder'],
            'propose_reminder_cancel' => ['reminder_id' => $f['reminder']->id],
        };
    }

    private function propose(array $f, string $name, array $arguments, ?string $key = null): array
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $call = 'call_'.str_replace('-', '', (string) Str::uuid());
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('Already executed!'), $this->aiCall($name, json_encode($arguments, JSON_THROW_ON_ERROR), $call)]))->push($this->aiResponse([$this->aiText('Created, completed and delivered successfully!')]));

        return $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Propose one change.'], ['Idempotency-Key' => $key ?? (string) Str::uuid()])->assertOk()->json('data');
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['tasks', 'subtasks', 'study_sessions', 'reminders', 'notifications'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $snapshot;
    }

    public static function actions(): array
    {
        return [0 => ['propose_subtask_create']];
    }

    #[DataProvider('actions')]
    public function test_all_actions_propose_without_business_writes_commit_with_turn_and_execute_once_without_provider(string $name): void
    {
        $f = $this->fixture();
        if ($name === 'propose_subtask_reopen') {
            $f['child']->update(['status' => 'completed', 'completed_at' => now('UTC')->startOfSecond()]);
        }
        $before = $this->snapshot();
        $key = (string) Str::uuid();
        $arguments = $this->args($f, $name);
        $turn = $this->propose($f, $name, $arguments, $key);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('confirmation_required', $turn['action']['state']);
        $this->assertSame($turn['tools'][0]['call_id'], AiPendingAction::sole()->call_id);
        $this->assertEquals($arguments, AiPendingAction::sole()->arguments);
        $this->assertTrue($turn['action']['preview']['confirmation_required']);
        $this->assertStringContainsString('підготовлено до підтвердження', $turn['content']);
        $replay = $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Propose one change.'], ['Idempotency-Key' => $key])->assertOk()->json('data');
        $this->assertEquals($turn, $replay);
        $this->assertDatabaseCount('ai_pending_actions', 1);
        $id = $turn['action']['id'];
        $this->getJson($this->url($f, $id))->assertOk()->assertJsonPath('data.type', $name)->assertJsonPath('data.receipt', null);
        $this->getJson($this->url($f))->assertOk()->assertJsonPath('meta.total', 1);
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->never())->method('respond');
        $this->app->instance(LlmProvider::class, $provider);
        $receipt = $this->decide($f, $id)->assertOk()->assertJsonPath('data.status', 'executed')->assertJsonPath('replayed', false)->json('data.receipt');
        $domain = str_starts_with($name, 'propose_subtask_') ? 'subtask' : 'reminder';
        $this->assertSame($name, $receipt['type']);
        $this->assertSame($id, $receipt['action_id']);
        $this->assertArrayNotHasKey('user_id', $receipt[$domain]);
        if ($domain === 'subtask') {
            $this->assertSame($f['task']->id, $receipt[$domain]['task_id']);
            $this->assertSame($name === 'propose_subtask_complete' ? 'completed' : 'pending', $receipt[$domain]['status']);
        } else {
            $this->assertSame($name === 'propose_reminder_cancel' ? 'cancelled' : 'scheduled', $receipt[$domain]['status']);
        }
        $after = $this->snapshot();
        $this->assertSame($before['tasks'], $after['tasks']);
        if ($name === 'propose_subtask_complete') {
            $this->assertSame('cancelled', $f['session']->fresh()->status->value);
            $this->assertSame(count($before['study_sessions']), count($after['study_sessions']));
        } else {
            $this->assertSame($before['study_sessions'], $after['study_sessions']);
        }
        $this->assertSame($before['notifications'], $after['notifications']);
        $this->decide($f, $id)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.receipt', $receipt);
        $this->assertSame($after, $this->snapshot());
        $this->decide($f, $id, 'reject')->assertConflict()->assertJsonPath('code', 'action_executed');
        $history = $this->getJson('/api/ai/conversations/'.$f['conversation']->id.'/messages')->assertOk()->json('data');
        $this->assertSame($receipt, end($history)['action_event']['receipt']);
        $this->assertStringNotContainsString('Already executed!', json_encode($history));
        $this->assertStringNotContainsString('delivered successfully', json_encode($history));
        $this->assertSame(1, $f['conversation']->messages()->where('tool_calls->version', 2)->count());
        Http::assertSentCount(2);
    }

    #[DataProvider('actions')]
    public function test_rejection_and_expiration_never_change_business_data_for_any_action(string $name): void
    {
        $f = $this->fixture();
        if ($name === 'propose_subtask_reopen') {
            $f['child']->update(['status' => 'completed', 'completed_at' => now('UTC')->startOfSecond()]);
        }
        $before = $this->snapshot();
        $turn = $this->propose($f, $name, $this->args($f, $name));
        $this->decide($f, $turn['action']['id'], 'reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $messages = AiMessage::count();
        $this->decide($f, $turn['action']['id'], 'reject')->assertOk();
        $this->decide($f, $turn['action']['id'])->assertConflict();
        $this->assertSame($messages, AiMessage::count());
        $turn = $this->propose($f, $name, $this->args($f, $name));
        DB::table('ai_pending_actions')->where('id', $turn['action']['id'])->update(['expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        $this->travelTo(now()->subYear());
        $this->getJson($this->url($f, $turn['action']['id']))->assertOk()->assertJsonPath('data.status', 'expired');
        $this->decide($f, $turn['action']['id'])->assertStatus(410)->assertJsonPath('data.receipt', null);
        $this->decide($f, $turn['action']['id'])->assertStatus(410);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_subtask_position_is_assigned_only_at_confirmation_and_capacity_is_authoritative(): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, 'propose_subtask_create', $this->args($f, 'propose_subtask_create'));
        $this->assertArrayNotHasKey('position', $turn['action']['preview']['changes']);
        Subtask::factory()->for($f['task'])->create(['position' => 42]);
        $this->decide($f, $turn['action']['id'])->assertOk()->assertJsonPath('data.receipt.subtask.position', 43);
        $turn = $this->propose($f, 'propose_subtask_create', $this->args($f, 'propose_subtask_create'));
        Subtask::factory()->for($f['task'])->create(['position' => 65535]);
        $before = $this->snapshot();
        $this->decide($f, $turn['action']['id'])->assertUnprocessable()->assertJsonPath('code', 'action_validation_failed');
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(AiPendingAction::findOrFail($turn['action']['id'])->receipt);
    }

    public function test_subtask_null_omission_parent_deadlines_and_reminder_synchronization_use_shared_domain_rules(): void
    {
        $f = $this->fixture();
        $reminder = app(ReminderService::class)->create($f['owner'], ['subtask_id' => $f['child']->id, 'anchor' => 'subtask_deadline', 'offset_minutes' => -60]);
        $new = $this->time(5);
        $turn = $this->propose($f, 'propose_subtask_update', ['task_id' => $f['task']->id, 'subtask_id' => $f['child']->id, 'deadline_at' => $new, 'estimated_minutes' => null]);
        $sessionBefore = $f['session']->fresh()->getAttributes();
        $this->decide($f, $turn['action']['id'])->assertOk()->assertJsonPath('data.receipt.subtask.description', 'Preserve me');
        $this->assertSame(CarbonImmutable::parse($new, 'UTC')->subHour()->format('Y-m-d H:i:s'), $reminder->fresh()->getRawOriginal('trigger_at'));
        $this->assertSame($sessionBefore, $f['session']->fresh()->getAttributes());
        $bad = $this->propose($f, 'propose_subtask_update', ['task_id' => $f['task']->id, 'subtask_id' => $f['child']->id, 'deadline_at' => $this->time(8)]);
        $this->assertArrayNotHasKey('action', $bad);
        $turn = $this->propose($f, 'propose_subtask_update', ['task_id' => $f['task']->id, 'subtask_id' => $f['child']->id, 'deadline_at' => null]);
        $this->decide($f, $turn['action']['id'])->assertOk()->assertJsonPath('data.receipt.subtask.deadline_at', null);
        $this->assertSame('cancelled', $reminder->fresh()->status->value);
    }

    public function test_subtask_completion_cancels_only_appropriate_reminders_and_reopening_recreates_nothing(): void
    {
        $f = $this->fixture();
        $own = app(ReminderService::class)->create($f['owner'], ['subtask_id' => $f['child']->id, 'trigger_at' => $this->time()]);
        $sessionReminder = app(ReminderService::class)->create($f['owner'], ['study_session_id' => $f['session']->id, 'trigger_at' => $this->time()]);
        $parentReminder = app(ReminderService::class)->create($f['owner'], ['task_id' => $f['task']->id, 'trigger_at' => $this->time()]);
        $taskBefore = $f['task']->fresh()->getAttributes();
        $sessionBefore = $f['session']->fresh()->getAttributes();
        $sibling = Subtask::factory()->for($f['task'])->create();
        $siblingSession = StudySession::factory()->for($f['task'])->create(['subtask_id' => $sibling->id, 'starts_at' => now('UTC')->addDays(3)->startOfSecond(), 'ends_at' => now('UTC')->addDays(3)->addHour()->startOfSecond()]);
        $parentSession = StudySession::factory()->for($f['task'])->create(['starts_at' => now('UTC')->addDays(3)->startOfSecond(), 'ends_at' => now('UTC')->addDays(3)->addHour()->startOfSecond()]);
        $started = StudySession::factory()->for($f['task'])->create(['subtask_id' => $f['child']->id, 'starts_at' => now('UTC')->subHour()->startOfSecond(), 'ends_at' => now('UTC')->addHour()->startOfSecond()]);
        $untouched = collect([$sibling, $siblingSession, $parentSession, $started]);
        $untouchedBefore = $untouched->map(fn ($record) => $record->fresh()->getAttributes())->all();
        $turn = $this->propose($f, 'propose_subtask_complete', $this->args($f, 'propose_subtask_complete'));
        $this->assertSame(1, $turn['action']['preview']['completion_review']['future_sessions_count']);
        $this->assertSame('planned', $f['session']->fresh()->status->value);
        $this->decide($f, $turn['action']['id'])->assertOk();
        $this->assertSame('cancelled', $own->fresh()->status->value);
        $this->assertSame('cancelled', $sessionReminder->fresh()->status->value);
        $this->assertSame('scheduled', $parentReminder->fresh()->status->value);
        $remindersBefore = DB::table('reminders')->orderBy('id')->get()->toArray();
        $turn = $this->propose($f, 'propose_subtask_reopen', $this->args($f, 'propose_subtask_reopen'));
        $this->decide($f, $turn['action']['id'])->assertOk()->assertJsonPath('data.receipt.subtask.completed_at', null);
        $this->assertEquals($remindersBefore, DB::table('reminders')->orderBy('id')->get()->toArray());
        $this->assertSame($taskBefore, $f['task']->fresh()->getAttributes());
        $this->assertSame('cancelled', $f['session']->fresh()->status->value);
        $this->assertNull($f['session']->fresh()->actual_minutes);
        $this->assertNull($f['session']->fresh()->completed_at);
        $this->assertSame($untouchedBefore, $untouched->map(fn ($record) => $record->fresh()->getAttributes())->all());
        $this->assertSame($sessionBefore['starts_at'], $f['session']->fresh()->getRawOriginal('starts_at'));
    }

    public static function reminderModes(): array
    {
        return [0 => [null, false]];
    }

    #[DataProvider('reminderModes')]
    public function test_reminder_creation_previews_and_confirms_absolute_and_relative_modes(?string $field, bool $relative): void
    {
        $f = $this->fixture();
        $data = ['message' => 'Review notes'];
        if ($field !== null) {
            $data[$field] = $f[match ($field) {
                'task_id' => 'task',
                'subtask_id' => 'child',
                default => 'session',
            }]->id;
        }
        if ($relative) {
            $data['anchor'] = match ($field) {
                'task_id' => 'task_deadline',
                'subtask_id' => 'subtask_deadline',
                default => 'session_start',
            };
            $data['offset_minutes'] = -60;
        } else {
            $data['trigger_at'] = $this->time();
        }
        $before = $this->snapshot();
        $turn = $this->propose($f, 'propose_reminder_create', $data);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($relative ? 'relative' : 'absolute', $turn['action']['preview']['mode']);
        $expected = $relative ? CarbonImmutable::parse($f[match ($field) {
            'task_id' => 'task',
            'subtask_id' => 'child',
            default => 'session',
        }]->getRawOriginal($field === 'study_session_id' ? 'starts_at' : 'deadline_at'), 'UTC')->subHour()->format('Y-m-d\TH:i:s\Z') : $data['trigger_at'];
        $this->assertSame($expected, $turn['action']['preview']['changes']['trigger_at']);
        $receipt = $this->decide($f, $turn['action']['id'])->assertOk()->json('data.receipt.reminder');
        $this->assertSame($expected, $receipt['trigger_at']);
        $this->assertSame($data['anchor'] ?? null, $receipt['anchor']);
        $this->assertSame($data['offset_minutes'] ?? null, $receipt['offset_minutes']);
        $this->assertDatabaseCount('notifications', 0);
    }
}
