<?php

namespace Tests\Feature;

use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\StudySession;
use App\Models\Task;
use App\Services\AI\LlmProvider;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\InteractsWithPlanningActions;
use Tests\TestCase;

#[Group('ai')]
#[Group('mysql')]
class CompactAiPlanningActionsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithPlanningActions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => getenv('AI_TEST_PROVIDER') ?: 'openai', 'ai.gemini_key' => 'offline-gemini-test-key', 'ai.gemini_model' => 'gemini-offline-contract-fixture', 'ai.openai_key' => 'fake', 'ai.model' => 'gpt-5.4-mini', 'ai.requests_per_minute' => 60]);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public static function actions(): array
    {
        return [0 => ['propose_study_plan_create']];
    }

    #[DataProvider('actions')]
    public function test_complete_review_commits_only_with_turn_and_confirmation_executes_once_without_provider(string $name): void
    {
        $f = $this->planningFixture($name);
        $before = $this->planningSnapshot();
        $requestId = (string) Str::uuid();
        $response = $this->proposePlanning($f, requestId: $requestId)->assertOk();
        $turn = $response->json('data');
        $id = $turn['action']['id'];
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertSame('confirmation_required', $turn['action']['state']);
        $this->assertStringContainsString('підготовлено до підтвердження', $turn['content']);
        $action = AiPendingAction::findOrFail($id);
        $this->assertEquals($f['arguments'], $action->arguments);
        if (in_array($name, ['propose_study_plan_create', 'propose_adaptive_replanning'], true)) {
            $token = $action->protected_payload['preview_token'];
            $this->assertStringNotContainsString($token, $action->getRawOriginal('protected_payload'));
            $this->assertStringNotContainsString($token, $response->getContent());
            $this->assertArrayNotHasKey('protected_payload', $action->toArray());
            $this->assertLessThanOrEqual($action->protected_payload['expires_at'], $action->expires_at->utc()->format('Y-m-d\TH:i:s\Z'));
            Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()), $token));
        } else {
            $this->assertNull($action->protected_payload);
            if ($name === 'propose_session_reschedule') {
                $this->assertFalse($turn['action']['preview']['review']['feasibility_confirmed']);
            } else {
                $this->assertTrue($turn['action']['preview']['review']['confirmation_required']);
            }
        }
        foreach ([$response->getContent(), $this->getJson($this->planningUrl($f, $id))->assertOk()->getContent(), $this->getJson('/api/ai/conversations/'.$f['conversation']->id.'/messages')->assertOk()->getContent()] as $public) {
            $this->assertStringNotContainsString('preview_token', $public);
            $this->assertStringNotContainsString('protected_payload', $public);
            $this->assertStringNotContainsString('Already applied', $public);
        }
        $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one planning change.'], ['Idempotency-Key' => $requestId])->assertOk()->assertJsonPath('data.action.id', $id);
        $this->assertDatabaseCount('ai_pending_actions', 1);
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->never())->method('respond');
        $this->app->instance(LlmProvider::class, $provider);
        $receipt = $this->confirmPlanning($f, $id)->assertOk()->assertJsonPath('data.status', 'executed')->json('data.receipt');
        $this->assertTrue($receipt['applied']);
        $this->assertSame($id, $receipt['action_id']);
        $this->assertSame($name, $receipt['type']);
        $this->assertStringNotContainsString('user_id', json_encode($receipt));
        $this->assertStringNotContainsString('preview_token', json_encode($receipt));
        if (in_array($name, ['propose_session_mark_missed', 'propose_session_complete'], true)) {
            $this->assertSame($name === 'propose_session_complete' ? 'completed' : 'missed', $f['source']->fresh()->status->value);
            $this->assertSame('cancelled', $f['reminder']->fresh()->status->value);
            $this->assertSame(0, StudySession::where('rescheduled_from_session_id', $f['source']->id)->count());
        } elseif ($f['source'] !== null) {
            $this->assertSame('rescheduled', $f['source']->fresh()->status->value);
            $this->assertSame('cancelled', $f['reminder']->fresh()->status->value);
            $replacement = StudySession::where('rescheduled_from_session_id', $f['source']->id)->sole();
            $this->assertSame($f['task']->id, $replacement->task_id);
        } else {
            $this->assertSame(count($turn['action']['preview']['review']['sessions']), count($receipt['sessions']));
            $this->assertSame($turn['action']['preview']['review']['summary'], $receipt['summary']);
        }
        $this->assertDatabaseCount('notifications', 0);
        $after = $this->planningSnapshot();
        $this->confirmPlanning($f, $id)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.receipt', $receipt);
        $this->confirmPlanning($f, $id, operation: 'reject')->assertConflict()->assertJsonPath('code', 'action_executed');
        $this->assertSame($after, $this->planningSnapshot());
        $this->assertSame(1, $f['conversation']->messages()->where('tool_calls->version', 2)->count());
        $createdId = $receipt['sessions'][0]['id'] ?? $receipt['replacement_sessions'][0]['id'] ?? $receipt['replacement']['id'] ?? $receipt['session']['id'];
        StudySession::findOrFail($createdId)->update(['status' => 'cancelled', 'completed_at' => null, 'actual_minutes' => null]);
        $later = $this->planningSnapshot();
        $this->confirmPlanning($f, $id)->assertOk()->assertJsonPath('data.receipt', $receipt);
        $this->assertSame($later, $this->planningSnapshot());
    }

    public function test_partial_plan_requires_client_boolean_and_preserves_every_reviewed_session(): void
    {
        $f = $this->planningFixture();
        $f['task']->update(['estimated_minutes' => 600]);
        $turn = $this->proposePlanning($f)->assertOk()->json('data');
        $this->assertFalse($turn['action']['preview']['review']['summary']['fully_allocated']);
        $this->assertSame(['accept_partial'], $turn['action']['preview']['required_consent']);
        $id = $turn['action']['id'];
        foreach ([[], ['accept_partial' => false]] as $flags) {
            $this->confirmPlanning($f, $id, $flags)->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
            $this->assertDatabaseCount('study_sessions', 0);
        }
        $this->confirmPlanning($f, $id, ['accept_partial' => true])->assertOk()->assertJsonPath('data.receipt.summary.fully_allocated', false);
        $this->assertDatabaseCount('study_sessions', count($turn['action']['preview']['review']['sessions']));
    }

    public static function shortfall(): array
    {
        return [0 => [false]];
    }

    #[DataProvider('shortfall')]
    public function test_adaptive_partial_and_unplaced_consequences_have_distinct_required_consents(bool $unplaced): void
    {
        $f = $this->planningFixture('propose_adaptive_replanning');
        $f['window']->update(['starts_at' => $unplaced ? '10:00:00' : '09:30:00', 'ends_at' => $unplaced ? '11:00:00' : '10:00:00']);
        $turn = $this->proposePlanning($f)->assertOk()->json('data');
        $id = $turn['action']['id'];
        $required = $turn['action']['preview']['required_consent'];
        $this->assertSame($unplaced ? ['accept_partial', 'accept_unplaced_cancellation'] : ['accept_partial'], $required);
        $before = $this->planningSnapshot();
        $this->confirmPlanning($f, $id)->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        if ($unplaced) {
            $this->confirmPlanning($f, $id, ['accept_partial' => true])->assertUnprocessable()->assertJsonValidationErrors('accept_unplaced_cancellation');
            $this->confirmPlanning($f, $id, ['accept_unplaced_cancellation' => true])->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        }
        $this->assertSame($before, $this->planningSnapshot());
        $this->confirmPlanning($f, $id, ['accept_partial' => true, 'accept_unplaced_cancellation' => $unplaced])->assertOk()->assertJsonPath('data.receipt.summary.untransferred_seconds', $unplaced ? 3600 : 1800);
        $this->assertSame($unplaced ? 'cancelled' : 'rescheduled', $f['source']->fresh()->status->value);
        $this->assertDatabaseCount('study_sessions', $unplaced ? 1 : 2);
    }

    public static function noAction(): array
    {
        return [0 => ['propose_study_plan_create', 'no_work']];
    }

    #[DataProvider('noAction')]
    public function test_empty_or_nonactionable_reviews_never_leave_a_confirmable_action(string $name, string $condition): void
    {
        $f = $this->planningFixture($name);
        match ($condition) {
            'no_work' => $f['task']->update(['estimated_minutes' => null]),
            'no_availability' => $f['window']->delete(),
            'valid' => $f['lesson']->update(['status' => 'cancelled']),
        };
        $before = $this->planningSnapshot();
        $response = $this->proposePlanning($f)->assertOk();
        $this->assertArrayNotHasKey('action', $response->json('data'));
        $this->assertStringContainsString('Пропозицію не створено', $response->json('data.content'));
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertSame($before, $this->planningSnapshot());
    }

    public static function changes(): array
    {
        return [0 => ['propose_study_plan_create', 'deadline']];
    }

    #[DataProvider('changes')]
    public function test_material_changes_cannot_be_applied_silently_or_fabricate_receipts(string $name, string $change): void
    {
        $f = $this->planningFixture($name);
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        match ($change) {
            'deadline' => $f['task']->update(['deadline_at' => $f['date']->setHour(9)]),
            'estimate' => $f['task']->update(['estimated_minutes' => 30]),
            'deleted' => $f['task']->delete(),
            'completed' => $f['task']->update(['status' => 'completed', 'completed_at' => now('UTC')]),
            'availability' => $f['window']->delete(),
            'preferences' => PlanningPreference::factory()->for($f['owner'])->create(['max_daily_study_minutes' => 30]),
            'lesson' => Lesson::factory()->for($f['owner'])->create(['starts_at' => $f['date']->setHour(9), 'ends_at' => $f['date']->setHour(18)]),
            'timezone' => $f['owner']->update(['timezone' => 'America/New_York']),
            'interval' => $f['source']->update(['ends_at' => $f['date']->setHour(11)->addMinute()]),
            'attribution' => $f['source']->update(['task_id' => Task::factory()->for($f['owner'])->create()->id]),
            'chain' => $f['source']->update(['rescheduled_from_session_id' => StudySession::factory()->for($f['task'])->create()->id]),
            'source_cancelled' => $f['source']->update(['status' => 'cancelled']),
        };
        $before = $this->planningSnapshot();
        $this->confirmPlanning($f, $id)->assertConflict()->assertJsonPath('code', 'action_stale');
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertNull(AiPendingAction::findOrFail($id)->receipt);
    }

    public static function invalidConsent(): array
    {
        return [0 => ['propose_study_plan_create', ['accept_partial' => null]]];
    }

    #[DataProvider('invalidConsent')]
    public function test_confirmation_rejects_coerced_or_unreviewed_fields(string $name, array $body): void
    {
        $f = $this->planningFixture($name);
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        $before = $this->planningSnapshot();
        $this->confirmPlanning($f, $id, $body)->assertUnprocessable();
        $this->confirmPlanning($f, $id, ['accept_partial' => true], 'reject')->assertUnprocessable();
        $this->assertSame($before, $this->planningSnapshot());
    }

    public static function failures(): array
    {
        return [0 => ['propose_study_plan_create', 'business']];
    }

    #[DataProvider('failures')]
    public function test_failure_after_business_or_receipt_write_rolls_back_every_domain_effect(string $name, string $failure): void
    {
        $f = $this->planningFixture($name);
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        $before = $this->planningSnapshot();
        $count = AiMessage::count();
        $fail = fn () => throw new RuntimeException('SECRET internal write failed');
        match ($failure) {
            'business' => in_array($name, ['propose_session_mark_missed', 'propose_session_complete'], true) ? StudySession::updated($fail) : StudySession::created($fail),
            'receipt' => AiPendingAction::updated($fail),
            'history' => AiMessage::creating($fail),
        };
        try {
            $response = $this->confirmPlanning($f, $id)->assertStatus(500)->assertJsonPath('code', 'persistence_error');
            $this->assertStringNotContainsString('SECRET', $response->getContent());
        } finally {
            foreach ([StudySession::class, AiPendingAction::class, AiMessage::class] as $model) {
                $model::flushEventListeners();
            }
        }
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertSame($count, AiMessage::count());
        $this->assertNull(AiPendingAction::findOrFail($id)->receipt);
        $this->confirmPlanning($f, $id)->assertOk();
    }
}
