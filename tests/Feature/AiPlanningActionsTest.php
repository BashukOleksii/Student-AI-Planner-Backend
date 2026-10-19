<?php

namespace Tests\Feature;

use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\Actions\PlanningActionRegistry;
use App\Services\AI\Actions\PlanningActionService;
use App\Services\AI\LlmProvider;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningPersistenceService;
use App\Services\Planning\StudySessionReschedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithPlanningActions;
use Tests\TestCase;

class AiPlanningActionsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithPlanningActions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.openai_key' => 'fake', 'ai.model' => 'gpt-5.4-mini', 'ai.requests_per_minute' => 60]);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public static function actions(): array
    {
        return array_map(fn ($name) => [$name], PlanningActionRegistry::NAMES);
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
        $this->assertStringContainsString('Confirmation is required', $turn['content']);
        $action = AiPendingAction::findOrFail($id);
        $this->assertEquals($f['arguments'], $action->arguments);
        if ($name !== 'propose_session_reschedule') {
            $token = $action->protected_payload['preview_token'];
            $this->assertStringNotContainsString($token, $action->getRawOriginal('protected_payload'));
            $this->assertStringNotContainsString($token, $response->getContent());
            $this->assertArrayNotHasKey('protected_payload', $action->toArray());
            $this->assertLessThanOrEqual($action->protected_payload['expires_at'], $action->expires_at->utc()->format('Y-m-d\TH:i:s\Z'));
            Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()), $token));
        } else {
            $this->assertNull($action->protected_payload);
            $this->assertFalse($turn['action']['preview']['review']['feasibility_confirmed']);
        }
        foreach ([$response->getContent(), $this->getJson($this->planningUrl($f, $id))->assertOk()->getContent(),
            $this->getJson('/api/ai/conversations/'.$f['conversation']->id.'/messages')->assertOk()->getContent()] as $public) {
            $this->assertStringNotContainsString('preview_token', $public);
            $this->assertStringNotContainsString('protected_payload', $public);
            $this->assertStringNotContainsString('Already applied', $public);
        }
        $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one planning change.'],
            ['Idempotency-Key' => $requestId])->assertOk()->assertJsonPath('data.action.id', $id);
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
        if ($f['source'] !== null) {
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
        // A receipt is the committed result, even after later legitimate domain changes.
        $createdId = $receipt['sessions'][0]['id'] ?? $receipt['replacement_sessions'][0]['id'] ?? $receipt['replacement']['id'];
        StudySession::findOrFail($createdId)->update(['status' => 'cancelled']);
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
        return [[false], [true]];
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
        $this->confirmPlanning($f, $id, ['accept_partial' => true, 'accept_unplaced_cancellation' => $unplaced])->assertOk()
            ->assertJsonPath('data.receipt.summary.untransferred_seconds', $unplaced ? 3600 : 1800);
        $this->assertSame($unplaced ? 'cancelled' : 'rescheduled', $f['source']->fresh()->status->value);
        $this->assertDatabaseCount('study_sessions', $unplaced ? 1 : 2);
    }

    public static function noAction(): array
    {
        return [['propose_study_plan_create', 'no_work'], ['propose_study_plan_create', 'no_availability'],
            ['propose_adaptive_replanning', 'valid']];
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
        $this->assertStringContainsString('No planning action was prepared', $response->json('data.content'));
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertSame($before, $this->planningSnapshot());
    }

    public static function changes(): array
    {
        $cases = [];
        foreach (PlanningActionRegistry::NAMES as $name) {
            foreach (['deadline', 'estimate', 'deleted', 'completed'] as $change) {
                $cases[] = [$name, $change];
            }
        }
        foreach (array_slice(PlanningActionRegistry::NAMES, 0, 2) as $name) {
            foreach (['availability', 'preferences', 'lesson', 'timezone'] as $change) {
                $cases[] = [$name, $change];
            }
        }
        foreach (['propose_adaptive_replanning', 'propose_session_reschedule'] as $name) {
            foreach (['interval', 'attribution', 'chain', 'source_cancelled'] as $change) {
                $cases[] = [$name, $change];
            }
        }

        return $cases;
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
        $cases = [];
        foreach ([null, 0, 1, 'true', 'false', [], 1.0] as $value) {
            $cases[] = ['propose_study_plan_create', ['accept_partial' => $value]];
            $cases[] = ['propose_adaptive_replanning', ['accept_unplaced_cancellation' => $value]];
        }
        foreach (['preview_token', 'user_id', 'sessions', 'starts_at', 'tool', 'source_ids'] as $field) {
            $cases[] = ['propose_study_plan_create', [$field => 'forged']];
        }
        $cases[] = ['propose_study_plan_create', ['accept_unplaced_cancellation' => true]];
        $cases[] = ['propose_session_reschedule', ['accept_partial' => true]];

        return $cases;
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

    #[DataProvider('actions')]
    public function test_rejection_expiration_and_foreign_access_preserve_all_business_data(string $name): void
    {
        $f = $this->planningFixture($name);
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        $before = $this->planningSnapshot();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->confirmPlanning($f, $id)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($f['owner'], 'web');
        $this->confirmPlanning($f, $id, operation: 'reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->confirmPlanning($f, $id, operation: 'reject')->assertOk();
        $this->confirmPlanning($f, $id)->assertConflict();
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        DB::table('ai_pending_actions')->where('id', $id)->update(['expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        $this->travelBack();
        $this->confirmPlanning($f, $id)->assertStatus(410)->assertJsonPath('data.status', 'expired');
        $this->confirmPlanning($f, $id)->assertStatus(410);
        $this->assertSame($before, $this->planningSnapshot());
    }

    public static function failures(): array
    {
        $cases = [];
        foreach (PlanningActionRegistry::NAMES as $name) {
            foreach (['business', 'receipt', 'history'] as $failure) {
                $cases[] = [$name, $failure];
            }
        }

        return $cases;
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
            'business' => StudySession::created($fail), 'receipt' => AiPendingAction::updated($fail), 'history' => AiMessage::creating($fail),
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

    public static function turnFailures(): array
    {
        return [['provider'], ['transcript'], ['action'], ['lease'], ['token_expired']];
    }

    #[DataProvider('turnFailures')]
    public function test_failed_turns_never_leave_actions_tokens_or_partial_transcripts(string $kind): void
    {
        $f = $this->planningFixture();
        $before = $this->planningSnapshot();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall($f['name'], json_encode($f['arguments']))]))
            ->push($kind === 'provider' ? ['error' => 'SECRET'] : $this->aiResponse([$this->aiText()]), $kind === 'provider' ? 500 : 200);
        if ($kind === 'transcript') {
            AiMessage::creating(fn () => throw new RuntimeException('transcript'));
        } elseif ($kind === 'action') {
            AiPendingAction::created(fn () => throw new RuntimeException('action'));
        } elseif ($kind === 'lease') {
            AiPendingAction::created(fn () => DB::table('ai_conversation_turns')->update(['lease_expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]));
        } elseif ($kind === 'token_expired') {
            AiMessage::creating(fn () => $this->travel(16)->minutes());
        }
        try {
            $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Propose'],
                ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(match ($kind) {
                    'provider' => 502, 'lease' => 409, 'token_expired' => 422, default => 500,
                });
            $this->assertDatabaseCount('ai_pending_actions', 0);
            $this->assertDatabaseCount('ai_messages', 0);
            $this->assertSame($before, $this->planningSnapshot());
        } finally {
            AiMessage::flushEventListeners();
            AiPendingAction::flushEventListeners();
        }
    }

    public function test_oversized_complete_review_fails_without_truncation_or_action_storage(): void
    {
        $f = $this->planningFixture();
        config(['ai.max_tool_result_bytes' => 100]);
        $result = app(PlanningActionService::class)->prepare($f['owner'], $f['name'], $f['arguments'], 'large');
        $this->assertNull($result['proposal']);
        $this->assertSame('review_too_large', $result['result']['error']['code']);
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_public_outermost_guards_and_trusted_internal_transaction_requirements_remain(): void
    {
        $f = $this->planningFixture();
        $service = app(StudyPlanPersistenceService::class);
        $token = $service->preview($f['owner'], ...array_values($f['arguments']))['preview_token'];
        try {
            $service->confirmWithinTransaction($f['owner'], $token);
            $this->fail('Internal writer must require a transaction.');
        } catch (LogicException) {
            $this->assertDatabaseCount('study_sessions', 0);
        }
        DB::beginTransaction();
        try {
            $this->expectException(LogicException::class);
            $service->confirm($f['owner'], $token);
        } finally {
            DB::rollBack();
        }
    }

    public static function scopes(): array
    {
        return [['propose_study_plan_create'], ['propose_adaptive_replanning']];
    }

    #[DataProvider('scopes')]
    public function test_owned_subtask_scope_and_foreign_or_mismatched_scope_are_enforced(string $name): void
    {
        $f = $this->planningFixture($name);
        $child = Subtask::factory()->for($f['task'])->create(['estimated_minutes' => 240, 'deadline_at' => $f['date']->setHour(23)]);
        if ($f['source'] !== null) {
            $f['source']->update(['subtask_id' => $child->id]);
        }
        $foreign = Subtask::factory()->create();
        $other = Subtask::factory()->for(Task::factory()->for($f['owner']))->create();
        foreach ([['task_id' => $foreign->task_id], ['task_id' => $f['task']->id, 'subtask_id' => $foreign->id],
            ['task_id' => $f['task']->id, 'subtask_id' => $other->id], ['task_id' => 999999]] as $scope) {
            $before = $this->planningSnapshot();
            $response = $this->proposePlanning($f, [...$f['arguments'], ...$scope])->assertOk();
            $this->assertArrayNotHasKey('action', $response->json('data'));
            $this->assertFalse($response->json('data.tools.0.ok'));
            $this->assertSame($before, $this->planningSnapshot());
        }
        $turn = $this->proposePlanning($f, [...$f['arguments'], 'task_id' => $f['task']->id, 'subtask_id' => $child->id])->assertOk()->json('data');
        $this->confirmPlanning($f, $turn['action']['id'])->assertOk();
        $created = StudySession::where('user_id', $f['owner']->id)->where('status', 'planned')->get();
        $this->assertNotEmpty($created);
        foreach ($created as $session) {
            $this->assertSame($child->id, $session->subtask_id);
        }
    }

    public function test_adaptive_review_preserves_valid_cannot_replan_and_historical_reminders(): void
    {
        $f = $this->planningFixture('propose_adaptive_replanning');
        $valid = StudySession::factory()->for($f['task'])->create(['starts_at' => $f['date']->setHour(16), 'ends_at' => $f['date']->setHour(17)]);
        $finished = Task::factory()->for($f['owner'])->create(['status' => 'completed', 'completed_at' => now('UTC')]);
        $cannot = StudySession::factory()->for($finished)->create(['starts_at' => $f['date']->setHour(12), 'ends_at' => $f['date']->setHour(13)]);
        $sent = Reminder::factory()->for($f['owner'])->create(['study_session_id' => $f['source']->id, 'status' => 'sent', 'sent_at' => now('UTC')]);
        $cancelled = Reminder::factory()->for($f['owner'])->create(['study_session_id' => $f['source']->id, 'status' => 'cancelled']);
        $before = [$valid->fresh()->getAttributes(), $cannot->fresh()->getAttributes(), $sent->fresh()->getAttributes(), $cancelled->fresh()->getAttributes()];
        $turn = $this->proposePlanning($f)->assertOk()->json('data');
        $rows = collect($turn['action']['preview']['review']['session_results'])->keyBy('study_session_id');
        $this->assertSame('valid', $rows[$valid->id]['classification']);
        $this->assertSame('cannot_replan', $rows[$cannot->id]['classification']);
        $this->assertSame('requires_replanning', $rows[$f['source']->id]['classification']);
        $this->assertSame([$f['source']->id], $turn['action']['preview']['review']['released_session_ids']);
        $this->confirmPlanning($f, $turn['action']['id'])->assertOk();
        $this->assertSame($before, [$valid->fresh()->getAttributes(), $cannot->fresh()->getAttributes(), $sent->fresh()->getAttributes(), $cancelled->fresh()->getAttributes()]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function manualConflicts(): array
    {
        return [['lesson'], ['session'], ['break'], ['daily'], ['weekly'], ['remaining']];
    }

    #[DataProvider('manualConflicts')]
    public function test_preliminary_manual_review_never_bypasses_authoritative_feasibility(string $case): void
    {
        $f = $this->planningFixture('propose_session_reschedule');
        $turn = $this->proposePlanning($f)->assertOk()->json('data');
        $this->assertFalse($turn['action']['preview']['review']['feasibility_confirmed']);
        $other = Task::factory()->for($f['owner'])->create(['estimated_minutes' => 60]);
        if ($case === 'lesson') {
            Lesson::factory()->for($f['owner'])->create(['starts_at' => $f['date']->setHour(14), 'ends_at' => $f['date']->setHour(15)]);
        } elseif ($case === 'session') {
            StudySession::factory()->for($other)->create(['starts_at' => $f['date']->setHour(14), 'ends_at' => $f['date']->setHour(15)]);
        } elseif ($case === 'break') {
            PlanningPreference::factory()->for($f['owner'])->create(['preferred_break_minutes' => 15]);
            StudySession::factory()->for($other)->create(['starts_at' => $f['date']->setHour(13), 'ends_at' => $f['date']->setHour(14)]);
        } elseif ($case === 'remaining') {
            StudySession::factory()->for($f['task'])->create(['starts_at' => now('UTC')->subHours(5), 'ends_at' => now('UTC')->subHour(),
                'status' => 'completed', 'completed_at' => now('UTC')->subHour(), 'actual_minutes' => 240]);
        } else {
            PlanningPreference::factory()->for($f['owner'])->create([$case === 'daily' ? 'max_daily_study_minutes' : 'max_weekly_study_minutes' => 30]);
        }
        $before = $this->planningSnapshot();
        $this->confirmPlanning($f, $turn['action']['id'])->assertConflict()->assertJsonPath('code', 'action_conflict');
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertSame('planned', $f['source']->fresh()->status->value);
        $this->assertNull(AiPendingAction::findOrFail($turn['action']['id'])->receipt);
    }

    public static function manualPreflight(): array
    {
        return [['source_started'], ['replacement_started'], ['longer'], ['terminal']];
    }

    #[DataProvider('manualPreflight')]
    public function test_manual_preflight_rejects_invalid_lifecycle_or_transfer_before_proposal(string $case): void
    {
        $f = $this->planningFixture('propose_session_reschedule');
        if ($case === 'source_started') {
            $f['source']->update(['starts_at' => now('UTC')->subHour(), 'ends_at' => now('UTC')->addHour()]);
        } elseif ($case === 'terminal') {
            $f['source']->update(['status' => 'cancelled']);
        } elseif ($case === 'longer') {
            $f['arguments']['ends_at'] = $f['date']->setHour(16)->format('Y-m-d\TH:i:s\Z');
        } else {
            $f['arguments']['starts_at'] = now('UTC')->subHour()->format('Y-m-d\TH:i:s\Z');
            $f['arguments']['ends_at'] = now('UTC')->format('Y-m-d\TH:i:s\Z');
        }
        $before = $this->planningSnapshot();
        $response = $this->proposePlanning($f)->assertOk();
        $this->assertArrayNotHasKey('action', $response->json('data'));
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertDatabaseCount('ai_pending_actions', 0);
    }

    public function test_shorter_manual_transfer_reports_exact_untransferred_duration_and_preserves_receipt_after_later_change(): void
    {
        $f = $this->planningFixture('propose_session_reschedule');
        $f['arguments']['ends_at'] = $f['date']->setHour(14)->addMinutes(30)->format('Y-m-d\TH:i:s\Z');
        $turn = $this->proposePlanning($f)->assertOk()->json('data');
        $this->assertSame(1800, $turn['action']['preview']['review']['summary']['not_transferred_seconds']);
        $receipt = $this->confirmPlanning($f, $turn['action']['id'])->assertOk()->json('data.receipt');
        $this->assertSame(1800, $receipt['summary']['not_transferred_seconds']);
        StudySession::findOrFail($receipt['replacement']['id'])->update(['status' => 'cancelled']);
        $this->confirmPlanning($f, $turn['action']['id'])->assertOk()->assertJsonPath('data.receipt', $receipt);
    }

    #[DataProvider('scopes')]
    public function test_domain_token_expiry_or_tampering_is_enforced_even_if_action_clock_is_still_live(string $name): void
    {
        $f = $this->planningFixture($name);
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        $before = $this->planningSnapshot();
        $this->travel(16)->minutes();
        $this->confirmPlanning($f, $id)->assertUnprocessable()->assertJsonPath('code', 'action_token_invalid');
        $this->assertSame($before, $this->planningSnapshot());
        $this->travelBack();
        $action = AiPendingAction::findOrFail($id);
        $private = $action->protected_payload;
        $private['preview_token'] = 'tampered';
        $action->protected_payload = $private;
        $action->save();
        $this->confirmPlanning($f, $id)->assertUnprocessable()->assertJsonPath('code', 'action_token_invalid');
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertNull($action->fresh()->receipt);
    }

    public function test_mysql_prevents_persisting_a_token_already_expired_despite_a_slow_php_clock(): void
    {
        $f = $this->planningFixture();
        $this->travelTo(CarbonImmutable::now('UTC')->subMinutes(16));
        $this->proposePlanning($f)->assertStatus(410)->assertJsonPath('code', 'action_expired');
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_multi_round_discovery_and_safe_failed_outcome_survive_history_trimming_without_token_disclosure(): void
    {
        $f = $this->planningFixture();
        Http::fakeSequence()->push($this->aiResponse([$this->aiCall('list_tasks', '{}', 'discover')]))
            ->push($this->aiResponse([$this->aiCall($f['name'], json_encode($f['arguments']), 'propose')]))
            ->push($this->aiResponse([$this->aiText('Already applied!')]));
        $turn = $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Find tasks and propose a plan.'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
        $this->assertSame(['list_tasks', $f['name']], array_column($turn['tools'], 'name'));
        $f['task']->update(['estimated_minutes' => 30]);
        $this->confirmPlanning($f, $turn['action']['id'])->assertConflict()->assertJsonPath('code', 'action_stale');
        $this->getJson('/api/ai/conversations/'.$f['conversation']->id.'/messages')->assertOk()->assertJsonFragment(['error_code' => 'action_stale']);
        config(['ai.max_history_messages' => 1]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertCount(23, $request['tools']);
            $this->assertStringContainsString('action_stale', json_encode($request['input']));
            $this->assertStringContainsString('failed', json_encode($request['input']));
            $this->assertStringNotContainsString('preview_token', json_encode($request->data()));

            return Http::response($this->aiResponse([$this->aiText('The previous proposal is stale; request a fresh review.')]));
        });
        $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'What happened?'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public static function calendars(): array
    {
        return [['2027-03-28', 120, '2027-03-28T00:30:00Z', '2027-03-28T02:30:00Z'],
            ['2026-10-25', 240, '2026-10-24T23:30:00Z', '2026-10-25T03:30:00Z']];
    }

    #[DataProvider('calendars')]
    public function test_local_date_and_dst_previews_use_the_persisted_timezone_and_exact_utc_intervals(string $date, int $minutes, string $start, string $end): void
    {
        $f = $this->planningFixture();
        $f['owner']->update(['timezone' => 'Europe/Kyiv']);
        $day = CarbonImmutable::parse($date, 'UTC');
        $f['window']->update(['day_of_week' => $day->isoWeekday(), 'starts_at' => '02:30:00', 'ends_at' => '05:30:00']);
        $f['task']->update(['estimated_minutes' => $minutes, 'deadline_at' => $day->setHour(20)]);
        $f['arguments'] = ['start_date' => $date, 'end_date' => $date];
        $turn = $this->proposePlanning($f)->assertOk()->json('data');
        $review = $turn['action']['preview']['review'];
        $this->assertSame('Europe/Kyiv', $review['timezone']);
        $this->assertSame($start, $review['sessions'][0]['starts_at']);
        $this->assertSame($end, $review['sessions'][0]['ends_at']);
        $this->assertSame($minutes * 60, $review['sessions'][0]['duration_seconds']);
        $this->confirmPlanning($f, $turn['action']['id'])->assertOk()->assertJsonPath('data.receipt.sessions.0.starts_at', $start);
    }

    public function test_cross_domain_mutation_batches_abort_before_any_partial_proposal_persistence(): void
    {
        $f = $this->planningFixture();
        $before = $this->planningSnapshot();
        foreach (['propose_task_create', 'propose_adaptive_replanning', 'propose_session_reschedule'] as $other) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fakeSequence()->push($this->aiResponse([$this->aiCall($f['name'], json_encode($f['arguments']), 'first'),
                $this->aiCall($other, '{}', 'second')]));
            $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Make both changes'],
                ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('code', 'multiple_actions');
        }
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertSame($before, $this->planningSnapshot());
    }

    public function test_foreign_or_missing_manual_sources_are_not_disclosed_or_proposed(): void
    {
        $f = $this->planningFixture('propose_session_reschedule');
        $foreign = StudySession::factory()->create();
        foreach ([$foreign->id, 999999] as $id) {
            $response = $this->proposePlanning($f, [...$f['arguments'], 'study_session_id' => $id])->assertOk();
            $this->assertArrayNotHasKey('action', $response->json('data'));
            $this->assertSame('not_found', $response->json('data.tools.0.error_code'));
        }
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertSame('planned', $f['source']->fresh()->status->value);
    }

    public function test_adaptive_and_manual_internal_paths_require_transactions_and_public_guards_remain(): void
    {
        foreach (['propose_adaptive_replanning', 'propose_session_reschedule'] as $name) {
            $f = $this->planningFixture($name);
            $service = app($name === 'propose_adaptive_replanning' ? StudyReplanningPersistenceService::class
                : StudySessionReschedulingService::class);
            $token = $name === 'propose_adaptive_replanning' ? $service->preview($f['owner'], ...array_values($f['arguments']))['preview_token'] : null;
            try {
                if ($token !== null) {
                    $service->confirmWithinTransaction($f['owner'], $token);
                } else {
                    $service->rescheduleWithinTransaction($f['owner'], (string) $f['source']->id, $f['arguments']['starts_at'], $f['arguments']['ends_at']);
                }
                $this->fail('Internal execution requires an existing transaction.');
            } catch (LogicException) {
                $this->assertSame(0, DB::transactionLevel());
            }
            DB::beginTransaction();
            try {
                if ($token !== null) {
                    $service->confirm($f['owner'], $token);
                } else {
                    $service->reschedule($f['owner'], (string) $f['source']->id, $f['arguments']['starts_at'], $f['arguments']['ends_at']);
                }
                $this->fail('Public execution must reject ambient transactions.');
            } catch (LogicException) {
                $this->assertSame(1, DB::transactionLevel());
            } finally {
                DB::rollBack();
            }
            $this->assertSame('planned', $f['source']->fresh()->status->value);
        }
    }
}
