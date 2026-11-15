<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subject;
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
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

#[Group('ai')]
#[Group('mysql')]
class CompactAiTaskActionsTest extends TestCase
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
        $this->actingAs($owner, 'web');

        return [$owner, $conversation];
    }

    private function url(AiConversation $conversation, ?string $action = null): string
    {
        return '/api/ai/conversations/'.$conversation->id.'/actions'.($action === null ? '' : '/'.$action);
    }

    private function propose(AiConversation $conversation, string $name, array $arguments, ?string $key = null): array
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $callId = 'call_'.str_replace('-', '', (string) Str::uuid());
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('I already changed your task.'), $this->aiCall($name, json_encode($arguments, JSON_THROW_ON_ERROR), $callId)]))->push($this->aiResponse([$this->aiText('Created and completed successfully!')]));

        return $this->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Please manage my task.'], ['Idempotency-Key' => $key ?? (string) Str::uuid()])->assertOk()->json('data');
    }

    private function postAction(string $url, ?array $body = null)
    {
        return $body === null ? $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}') : $this->postJson($url, $body);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['tasks', 'subtasks', 'study_sessions', 'reminders', 'notifications'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    public function test_create_is_proposal_only_committed_once_and_confirmation_replays_immutable_receipt_without_provider(): void
    {
        [$owner, $conversation] = $this->fixture();
        $subject = Subject::factory()->for($owner)->create();
        $before = $this->snapshot();
        $key = (string) Str::uuid();
        $arguments = ['title' => 'Prepare mathematics assignment', 'description' => 'Exercises 1 to 5', 'subject_id' => $subject->id, 'priority' => 2, 'estimated_minutes' => 120, 'deadline_at' => '2026-10-23T17:00:00Z'];
        $turn = $this->propose($conversation, 'propose_task_create', $arguments, $key);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('confirmation_required', $turn['action']['state']);
        $this->assertSame('Пропозицію підготовлено до підтвердження. Зміни ще не виконано.', $turn['content']);
        $this->assertSame($turn['action']['id'], AiPendingAction::sole()->id);
        $this->assertSame($turn['tools'][0]['call_id'], AiPendingAction::sole()->call_id);
        $this->assertEquals($arguments, AiPendingAction::sole()->arguments);
        $this->assertStringNotContainsString('already changed', $this->getJson('/api/ai/conversations/'.$conversation->id.'/messages')->getContent());
        $this->assertStringNotContainsString('completed successfully', $this->getJson('/api/ai/conversations/'.$conversation->id.'/messages')->getContent());
        $replay = $this->postJson('/api/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Please manage my task.'], ['Idempotency-Key' => $key])->assertOk()->json('data');
        $this->assertEquals($turn, $replay);
        $this->assertDatabaseCount('ai_pending_actions', 1);
        $id = $turn['action']['id'];
        $detail = $this->getJson($this->url($conversation, $id))->assertOk();
        $detail->assertJsonPath('data.confirmation_required', true)->assertJsonPath('data.receipt', null);
        $this->getJson($this->url($conversation).'?per_page=1&page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->never())->method('respond');
        $this->app->instance(LlmProvider::class, $provider);
        $executed = $this->postAction($this->url($conversation, $id).'/confirm')->assertOk()->assertJsonPath('data.status', 'executed')->assertJsonPath('data.confirmation_required', false)->assertJsonPath('replayed', false)->json('data');
        $this->assertDatabaseCount('tasks', 1);
        $task = Task::sole();
        $this->assertSame($owner->id, $task->user_id);
        $this->assertSame($arguments['title'], $task->title);
        $this->assertSame($task->id, $executed['receipt']['task']['id']);
        $this->assertSame('pending', $executed['receipt']['task']['status']);
        $this->assertEquals($executed, $this->postAction($this->url($conversation, $id).'/confirm')->assertOk()->assertJsonPath('replayed', true)->json('data'));
        $this->assertDatabaseCount('tasks', 1);
        $task->update(['title' => 'Later edit']);
        $this->assertSame($arguments['title'], $this->postAction($this->url($conversation, $id).'/confirm')->assertOk()->json('data.receipt.task.title'));
        $this->postAction($this->url($conversation, $id).'/reject')->assertConflict()->assertJsonPath('code', 'action_executed');
        $history = $this->getJson('/api/ai/conversations/'.$conversation->id.'/messages')->assertOk()->json('data');
        $event = end($history);
        $this->assertSame('executed', $event['action_event']['status']);
        $this->assertSame($executed['receipt'], $event['action_event']['receipt']);
        Http::assertSentCount(2);
    }

    public function test_update_keeps_omitted_fields_clears_explicit_nulls_and_uses_taskservice(): void
    {
        [$owner, $conversation] = $this->fixture();
        $task = Task::factory()->for($owner)->create(['title' => 'Original', 'description' => 'Clear me', 'estimated_minutes' => 30]);
        $before = $task->fresh()->getAttributes();
        $turn = $this->propose($conversation, 'propose_task_update', ['task_id' => $task->id, 'description' => null]);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->postAction($this->url($conversation, $turn['action']['id']).'/confirm')->assertOk()->assertJsonPath('data.receipt.task.description', null);
        $this->assertSame('Original', $task->fresh()->title);
        $this->assertSame(30, $task->fresh()->estimated_minutes);
        $this->assertNull($task->fresh()->description);
    }

    public function test_deadline_update_reconciles_relative_reminder_and_preserves_sessions(): void
    {
        [$owner, $conversation] = $this->fixture();
        $old = now('UTC')->addDays(3)->startOfSecond();
        $new = $old->copy()->addDay();
        $task = Task::factory()->for($owner)->create(['deadline_at' => $old]);
        $session = StudySession::factory()->for($task)->create();
        $sessionBefore = $session->fresh()->getAttributes();
        $reminder = app(ReminderService::class)->create($owner, ['task_id' => $task->id, 'anchor' => 'task_deadline', 'offset_minutes' => -15]);
        $turn = $this->propose($conversation, 'propose_task_update', ['task_id' => $task->id, 'deadline_at' => $new->format('Y-m-d\TH:i:s\Z')]);
        $this->postAction($this->url($conversation, $turn['action']['id']).'/confirm')->assertOk();
        $this->assertSame($new->copy()->subMinutes(15)->format('Y-m-d H:i:s'), $reminder->fresh()->getRawOriginal('trigger_at'));
        $this->assertSame($sessionBefore, $session->fresh()->getAttributes());
    }

    public function test_completion_cancels_reviewed_future_sessions_preserves_history_and_reopening_recreates_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13T10:00:00Z'));
        [$owner, $conversation] = $this->fixture();
        $task = Task::factory()->for($owner)->create();
        $child = Subtask::factory()->for($task)->create();
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id]);
        $boundary = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 10:00:00', 'ends_at' => '2026-11-13 11:00:00']);
        $future = collect([null, $child->id])->map(fn ($childId) => StudySession::factory()->for($task)->create(['subtask_id' => $childId, 'starts_at' => '2026-11-14 10:00:00', 'ends_at' => '2026-11-14 11:00:00']));
        $reminders = [Reminder::factory()->for($owner)->for($task)->create(), Reminder::factory()->for($owner)->create(['subtask_id' => $child->id]), Reminder::factory()->for($owner)->create(['study_session_id' => $session->id])];
        foreach ($future as $planned) {
            $reminders[] = Reminder::factory()->for($owner)->create(['study_session_id' => $planned->id]);
        }
        $before = [$child->fresh()->getAttributes(), $session->fresh()->getAttributes(), $boundary->fresh()->getAttributes()];
        $turn = $this->propose($conversation, 'propose_task_complete', ['task_id' => $task->id]);
        $this->assertSame(2, $turn['action']['preview']['completion_review']['future_sessions_count']);
        foreach ($future as $planned) {
            $this->assertSame('planned', $planned->fresh()->status->value);
        }
        $this->assertStringContainsString('історія сесій збережуться', $turn['action']['preview']['effects'][0]);
        $this->postAction($this->url($conversation, $turn['action']['id']).'/confirm')->assertOk()->assertJsonPath('data.receipt.task.status', 'completed');
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
        foreach ($reminders as $reminder) {
            $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
        }
        foreach ($future as $planned) {
            $this->assertSame('cancelled', $planned->fresh()->status->value);
            $this->assertNull($planned->fresh()->actual_minutes);
            $this->assertNull($planned->fresh()->completed_at);
        }
        $futureBeforeReopen = $future->map(fn ($planned) => $planned->fresh()->getAttributes())->all();
        $turn = $this->propose($conversation, 'propose_task_reopen', ['task_id' => $task->id]);
        $reminderBefore = DB::table('reminders')->orderBy('id')->get()->toArray();
        $this->postAction($this->url($conversation, $turn['action']['id']).'/confirm')->assertOk()->assertJsonPath('data.receipt.task.status', 'pending');
        $this->assertNull($task->fresh()->completed_at);
        $this->assertSame($before, [$child->fresh()->getAttributes(), $session->fresh()->getAttributes(), $boundary->fresh()->getAttributes()]);
        $this->assertSame($futureBeforeReopen, $future->map(fn ($planned) => $planned->fresh()->getAttributes())->all());
        $this->assertEquals($reminderBefore, DB::table('reminders')->orderBy('id')->get()->toArray());
    }

    public function test_rejection_is_idempotent_and_never_mutates_tasks(): void
    {
        [, $conversation] = $this->fixture();
        $turn = $this->propose($conversation, 'propose_task_create', ['title' => 'Never created']);
        $before = $this->snapshot();
        $url = $this->url($conversation, $turn['action']['id']);
        $this->postAction($url.'/reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $count = AiMessage::count();
        $this->postAction($url.'/reject')->assertOk()->assertJsonPath('data.receipt', null);
        $this->postAction($url.'/confirm')->assertConflict()->assertJsonPath('data.status', 'rejected');
        $this->assertSame($count, AiMessage::count());
        $this->assertSame($before, $this->snapshot());
    }
}
