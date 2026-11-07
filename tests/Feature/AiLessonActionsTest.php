<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Lesson;
use App\Models\ScheduleImportBatch;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AI\Actions\LessonActionRegistry;
use App\Services\AI\Actions\LessonActionService;
use App\Services\AI\LlmProvider;
use App\Services\Schedule\LessonService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

class AiLessonActionsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => getenv('AI_TEST_PROVIDER') ?: 'openai', 'ai.gemini_key' => 'offline-gemini-test-key', 'ai.gemini_model' => 'gemini-offline-contract-fixture', 'ai.openai_key' => 'fake-stage11-key', 'ai.model' => 'gpt-5.4-mini', 'ai.requests_per_minute' => 60]);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $subject = Subject::factory()->for($owner)->create(['name' => 'Mathematics']);
        $teacher = Teacher::factory()->for($owner)->create(['name' => 'Professor Example']);
        $period = AcademicPeriod::factory()->for($owner)->create();
        $lesson = Lesson::factory()->for($owner)->create(['subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'academic_period_id' => $period->id, 'room' => '301']);
        $this->actingAs($owner, 'web');

        return compact('owner', 'conversation', 'subject', 'teacher', 'period', 'lesson');
    }

    private function args(array $f, string $name): array
    {
        return match ($name) {
            'propose_lesson_create', 'propose_lesson_replacement' => [
                ...($name === 'propose_lesson_replacement' ? ['lesson_id' => $f['lesson']->id] : []),
                'subject_id' => $f['subject']->id, 'teacher_id' => $f['teacher']->id, 'academic_period_id' => $f['period']->id,
                'type' => 'practical', 'room' => 'Lab 2', 'starts_at' => '2026-11-04T07:00:00Z', 'ends_at' => '2026-11-04T08:00:00Z'],
            'propose_lesson_update' => ['lesson_id' => $f['lesson']->id, 'room' => null],
            'propose_lesson_cancel' => ['lesson_id' => $f['lesson']->id],
        };
    }

    private function propose(array $f, string $name, ?array $arguments = null, ?string $key = null): array
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('Already changed your Lesson!'),
            $this->aiCall($name, json_encode($arguments ?? $this->args($f, $name), JSON_THROW_ON_ERROR), 'call_'.str_replace('-', '', (string) Str::uuid()))]))
            ->push($this->aiResponse([$this->aiText('Applied successfully!')]));

        return $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one Lesson change.'],
            ['Idempotency-Key' => $key ?? (string) Str::uuid()])->assertOk()->json('data');
    }

    private function url(array $f, string $id = '', string $operation = ''): string
    {
        return '/api/ai/conversations/'.$f['conversation']->id.'/actions'.($id === '' ? '' : '/'.$id).($operation === '' ? '' : '/'.$operation);
    }

    private function decide(array $f, string $id, string $operation = 'confirm', string $body = '{}')
    {
        return $this->call('POST', $this->url($f, $id, $operation), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['subjects', 'teachers', 'academic_periods', 'lessons', 'tasks', 'subtasks', 'study_sessions', 'reminders', 'notifications', 'schedule_import_batches', 'schedule_import_rows'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    public static function actions(): array
    {
        return array_map(fn ($name) => [$name], LessonActionRegistry::NAMES);
    }

    #[DataProvider('actions')]
    public function test_each_action_is_reviewed_without_writes_delegates_to_lesson_service_and_replays_one_receipt(string $name): void
    {
        $f = $this->fixture();
        $method = match ($name) {
            'propose_lesson_create' => 'create', 'propose_lesson_update' => 'update',
            'propose_lesson_cancel' => 'cancel', default => 'createReplacement',
        };
        $real = new LessonService;
        $writer = $this->createPartialMock(LessonService::class, ['create', 'update', 'cancel', 'createReplacement']);
        foreach (['create', 'update', 'cancel', 'createReplacement'] as $other) {
            if ($other !== $method) {
                $writer->expects($this->never())->method($other);
            }
        }
        $writer->expects($this->once())->method($method)->willReturnCallback(function (...$args) use ($real, $method) {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->assertSame('pending', AiPendingAction::sole()->status);

            return $real->$method(...$args);
        });
        $this->app->instance(LessonService::class, $writer);
        $before = $this->snapshot();
        $key = (string) Str::uuid();
        $turn = $this->propose($f, $name, key: $key);
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue($turn['tools'][0]['ok']);
        $this->assertSame('confirmation_required', $turn['action']['state']);
        $this->assertStringContainsString('Confirmation is required', $turn['content']);
        $this->assertSame($name, $turn['action']['preview']['type']);
        $this->assertSame('Mathematics', $turn['action']['preview']['after']['subject']['name']);
        $this->assertStringEndsWith('Z', $turn['action']['preview']['after']['starts_at']);
        $replay = $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one Lesson change.'],
            ['Idempotency-Key' => $key])->assertOk()->json('data');
        $this->assertSame($turn, $replay);
        $id = $turn['action']['id'];
        $this->getJson($this->url($f, $id))->assertOk()->assertJsonPath('data.type', $name)->assertJsonPath('data.receipt', null);
        $this->getJson($this->url($f))->assertOk()->assertJsonPath('meta.total', 1);
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->never())->method('respond');
        $this->app->instance(LlmProvider::class, $provider);
        $receipt = $this->decide($f, $id)->assertOk()->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('replayed', false)->json('data.receipt');
        $this->assertSame($id, $receipt['action_id']);
        $this->assertSame($name, $receipt['type']);
        $this->assertSame($name === 'propose_lesson_cancel' ? 'cancelled' : 'active', $receipt['lesson']['status']);
        if ($name === 'propose_lesson_replacement') {
            $this->assertSame('replaced', $receipt['original']['status']);
            $this->assertSame($f['lesson']->id, $receipt['lesson']['replaces_lesson_id']);
        }
        $this->assertArrayNotHasKey('user_id', $receipt['lesson']);
        $this->assertArrayNotHasKey('schedule_import_batch_id', $receipt['lesson']);
        $after = $this->snapshot();
        foreach (array_diff(array_keys($before), ['lessons']) as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        $this->decide($f, $id)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.receipt', $receipt);
        $this->assertSame($after, $this->snapshot());
        $this->decide($f, $id, 'reject')->assertConflict()->assertJsonPath('code', 'action_executed');
        $history = $this->getJson('/api/ai/conversations/'.$f['conversation']->id.'/messages')->assertOk()->json('data');
        $this->assertSame($receipt, end($history)['action_event']['receipt']);
        $this->assertStringNotContainsString('Already changed', json_encode($history));
        $this->assertStringContainsString('Lesson action executed', end($history)['content']);
        Http::assertSentCount(2);
    }

    #[DataProvider('actions')]
    public function test_rejection_and_expiry_leave_all_business_rows_unchanged(string $name): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $turn = $this->propose($f, $name);
        $id = $turn['action']['id'];
        $this->decide($f, $id, 'reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $messages = AiMessage::count();
        $this->decide($f, $id, 'reject')->assertOk();
        $this->decide($f, $id)->assertConflict();
        $this->assertSame($messages, AiMessage::count());
        $turn = $this->propose($f, $name);
        $id = $turn['action']['id'];
        DB::table('ai_pending_actions')->where('id', $id)->update(['expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        $this->getJson($this->url($f, $id))->assertOk()->assertJsonPath('data.state', 'expired');
        $this->decide($f, $id)->assertGone()->assertJsonPath('data.status', 'expired');
        $this->decide($f, $id)->assertGone();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, AiPendingAction::whereNotNull('receipt')->count());
    }

    public function test_foreign_associations_targets_and_missing_identifiers_never_create_proposals_or_disclose_names(): void
    {
        $f = $this->fixture();
        $other = User::factory()->create();
        $foreign = ['subject_id' => Subject::factory()->for($other)->create(['name' => 'PRIVATE SUBJECT'])->id,
            'teacher_id' => Teacher::factory()->for($other)->create(['name' => 'PRIVATE TEACHER'])->id,
            'academic_period_id' => AcademicPeriod::factory()->for($other)->create(['name' => 'PRIVATE PERIOD'])->id];
        $lesson = Lesson::factory()->for($other)->create();
        $cases = [];
        foreach ($foreign as $field => $id) {
            foreach (['propose_lesson_create', 'propose_lesson_update', 'propose_lesson_replacement'] as $name) {
                $cases[] = [$name, array_replace($this->args($f, $name), [$field => $id])];
            }
        }
        foreach (['propose_lesson_update', 'propose_lesson_cancel', 'propose_lesson_replacement'] as $name) {
            $cases[] = [$name, array_replace($this->args($f, $name), ['lesson_id' => $lesson->id])];
        }
        $cases[] = ['propose_lesson_create', ['type' => 'lecture', 'starts_at' => '2026-11-04T07:00:00Z', 'ends_at' => '2026-11-04T08:00:00Z']];
        $before = $this->snapshot();
        foreach ($cases as [$name, $args]) {
            $turn = $this->propose($f, $name, $args);
            $this->assertFalse($turn['tools'][0]['ok']);
            $this->assertNull($turn['action'] ?? null);
            $this->assertStringNotContainsString('PRIVATE', json_encode($turn));
        }
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertSame($before, $this->snapshot());
    }

    public static function staleChanges(): array
    {
        return [['same_second_edit'], ['cancel'], ['delete'], ['subject_label'], ['teacher_label'], ['period_label'], ['subject_owner'], ['replacement_history']];
    }

    #[DataProvider('staleChanges')]
    public function test_confirmation_rejects_changed_lesson_graph_and_reviewed_associations(string $change): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, 'propose_lesson_update');
        match ($change) {
            'same_second_edit' => DB::table('lessons')->where('id', $f['lesson']->id)->update(['room' => 'Unreviewed edit']),
            'cancel' => app(LessonService::class)->cancel($f['owner'], $f['lesson']),
            'delete' => app(LessonService::class)->delete($f['owner'], $f['lesson']),
            'subject_label' => $f['subject']->update(['name' => 'Renamed subject']),
            'teacher_label' => $f['teacher']->update(['name' => 'Renamed teacher']),
            'period_label' => $f['period']->update(['name' => 'Renamed period']),
            'subject_owner' => $f['subject']->update(['user_id' => User::factory()->create()->id]),
            'replacement_history' => app(LessonService::class)->createReplacement($f['owner'], $f['lesson'], $this->args($f, 'propose_lesson_replacement')),
        };
        $before = $this->snapshot();
        $this->decide($f, $turn['action']['id'])->assertConflict()->assertJsonPath('code', 'action_stale');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('pending', AiPendingAction::sole()->status);
        $this->assertNull(AiPendingAction::sole()->receipt);
    }

    public function test_invalid_instants_intervals_and_overlap_fail_preparation_but_adjacency_is_valid(): void
    {
        $f = $this->fixture();
        $args = $this->args($f, 'propose_lesson_create');
        foreach ([['starts_at' => '2026-02-30T07:00:00Z'], ['starts_at' => '2026-11-04T07:00:00+00:00'],
            ['ends_at' => $args['starts_at']], ['ends_at' => '2026-11-04T06:00:00Z'],
            ['starts_at' => '2026-10-05T06:00:00Z', 'ends_at' => '2026-10-05T07:00:00Z']] as $bad) {
            $result = $this->propose($f, 'propose_lesson_create', array_replace($args, $bad));
            $this->assertFalse($result['tools'][0]['ok']);
        }
        $before = $this->snapshot();
        $turn = $this->propose($f, 'propose_lesson_create', array_replace($args, ['starts_at' => '2026-10-05T07:30:00Z', 'ends_at' => '2026-10-05T08:30:00Z']));
        $this->assertSame($before, $this->snapshot());
        $this->decide($f, $turn['action']['id'])->assertOk();
    }

    public static function conflictActions(): array
    {
        return [['propose_lesson_create'], ['propose_lesson_update'], ['propose_lesson_replacement']];
    }

    #[DataProvider('conflictActions')]
    public function test_confirmation_revalidates_new_overlap_without_changing_the_pending_action(string $name): void
    {
        $f = $this->fixture();
        $args = $this->args($f, $name);
        if ($name === 'propose_lesson_update') {
            $args += ['starts_at' => '2026-11-04T07:00:00Z', 'ends_at' => '2026-11-04T08:00:00Z'];
        }
        $turn = $this->propose($f, $name, $args);
        Lesson::factory()->for($f['owner'])->create(['starts_at' => '2026-11-04 07:30:00', 'ends_at' => '2026-11-04 09:00:00']);
        $before = $this->snapshot();
        $this->decide($f, $turn['action']['id'])->assertUnprocessable()->assertJsonPath('code', 'action_validation_failed');
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(AiPendingAction::sole()->receipt);
    }

    public static function rollbackActions(): array
    {
        $cases = [];
        foreach (LessonActionRegistry::NAMES as $name) {
            foreach (['receipt', 'history'] as $failure) {
                $cases[] = [$name, $failure];
            }
        }

        return $cases;
    }

    #[DataProvider('rollbackActions')]
    public function test_receipt_or_history_failure_rolls_back_all_lesson_writes(string $name, string $failure): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, $name);
        $before = $this->snapshot();
        $messages = AiMessage::count();
        $event = $failure === 'history' ? 'eloquent.creating: '.AiMessage::class : 'eloquent.saving: '.AiPendingAction::class;
        $this->app['events']->listen($event, fn () => false);
        try {
            $this->decide($f, $turn['action']['id'])->assertStatus(500)->assertJsonPath('code', 'persistence_error');
        } finally {
            $this->app['events']->forget($event);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($messages, AiMessage::count());
        $this->assertSame('pending', AiPendingAction::sole()->status);
        $this->assertNull(AiPendingAction::sole()->receipt);
        $this->decide($f, $turn['action']['id'])->assertOk();
    }

    public function test_import_identity_is_immutable_and_imported_original_can_be_cancelled_or_manually_replaced(): void
    {
        $f = $this->fixture();
        $batch = ScheduleImportBatch::factory()->for($f['owner'])->create(['academic_period_id' => $f['period']->id]);
        $f['lesson']->update(['schedule_import_batch_id' => $batch->id, 'import_fingerprint' => str_repeat('a', 64)]);
        $turn = $this->propose($f, 'propose_lesson_update', ['lesson_id' => $f['lesson']->id, 'academic_period_id' => null]);
        $this->assertFalse($turn['tools'][0]['ok']);
        $turn = $this->propose($f, 'propose_lesson_update');
        $this->decide($f, $turn['action']['id'])->assertOk();
        $this->assertSame($batch->id, $f['lesson']->fresh()->schedule_import_batch_id);
        $this->assertSame(str_repeat('a', 64), $f['lesson']->fresh()->import_fingerprint);
        $turn = $this->propose($f, 'propose_lesson_replacement');
        $receipt = $this->decide($f, $turn['action']['id'])->assertOk()->json('data.receipt');
        $replacement = Lesson::findOrFail($receipt['lesson']['id']);
        $this->assertNull($replacement->schedule_import_batch_id);
        $this->assertNull($replacement->import_fingerprint);
        $turn = $this->propose($f, 'propose_lesson_cancel', ['lesson_id' => $replacement->id]);
        $this->decide($f, $turn['action']['id'])->assertOk();
        $this->assertSame('replaced', $f['lesson']->fresh()->status->value);
    }

    public function test_manual_replacement_reuses_deleted_history_and_never_converts_imported_history(): void
    {
        $f = $this->fixture();
        $history = app(LessonService::class)->createReplacement($f['owner'], $f['lesson'], $this->args($f, 'propose_lesson_replacement'));
        app(LessonService::class)->delete($f['owner'], $history);
        $history->refresh()->update(['import_fingerprint' => str_repeat('b', 64), 'academic_period_id' => $f['period']->id]);
        $before = $this->snapshot();
        $turn = $this->propose($f, 'propose_lesson_replacement');
        $this->assertFalse($turn['tools'][0]['ok']);
        $this->assertSame($before, $this->snapshot());
        $history->update(['import_fingerprint' => null]);
        $turn = $this->propose($f, 'propose_lesson_replacement');
        $this->assertSame($history->id, $turn['action']['preview']['after']['id']);
        $this->decide($f, $turn['action']['id'])->assertOk()->assertJsonPath('data.receipt.lesson.id', $history->id);
        $this->assertDatabaseCount('lessons', 2);
    }

    public function test_foreign_conversation_actions_and_client_edited_arguments_are_rejected(): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, 'propose_lesson_cancel');
        $id = $turn['action']['id'];
        $before = $this->snapshot();
        $this->decide($f, $id, body: '{"lesson_id":999}')->assertUnprocessable();
        $this->decide($f, $id, body: '{"accept_partial":true}')->assertUnprocessable();
        $otherConversation = AiConversation::factory()->for($f['owner'])->create();
        $this->getJson('/api/ai/conversations/'.$otherConversation->id.'/actions/'.$id)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($f, $id))->assertNotFound();
        $this->decide($f, $id)->assertNotFound();
        $this->decide($f, $id, 'reject')->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_read_only_prepare_persists_neither_action_nor_lesson_and_cancellation_lifecycle_is_enforced(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        foreach (LessonActionRegistry::NAMES as $name) {
            $result = app(LessonActionService::class)->prepare($f['owner'], $name, $this->args($f, $name), 'correlated');
            $this->assertTrue($result['result']['ok']);
            $this->assertSame('correlated', $result['proposal']['call_id']);
            $this->assertSame($before, $this->snapshot());
        }
        $this->assertDatabaseCount('ai_pending_actions', 0);
        app(LessonService::class)->cancel($f['owner'], $f['lesson']);
        foreach (['propose_lesson_cancel', 'propose_lesson_replacement'] as $name) {
            $result = app(LessonActionService::class)->prepare($f['owner'], $name, $this->args($f, $name), 'call');
            $this->assertFalse($result['result']['ok']);
        }
    }

    #[DataProvider('actions')]
    public function test_cancelled_model_write_never_produces_a_successful_receipt(string $name): void
    {
        $f = $this->fixture();
        $turn = $this->propose($f, $name);
        $before = $this->snapshot();
        $event = 'eloquent.saving: '.Lesson::class;
        $this->app['events']->listen($event, fn () => false);
        try {
            $this->decide($f, $turn['action']['id'])->assertStatus(500)->assertJsonPath('code', 'persistence_error');
        } finally {
            $this->app['events']->forget($event);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(AiPendingAction::sole()->receipt);
    }

    public function test_changed_deleted_replacement_history_is_stale_even_when_the_original_did_not_change(): void
    {
        $f = $this->fixture();
        $history = app(LessonService::class)->createReplacement($f['owner'], $f['lesson'], $this->args($f, 'propose_lesson_replacement'));
        app(LessonService::class)->delete($f['owner'], $history);
        $turn = $this->propose($f, 'propose_lesson_replacement');
        DB::table('lessons')->where('id', $history->id)->update(['room' => 'Changed deleted history']);
        $before = $this->snapshot();
        $this->decide($f, $turn['action']['id'])->assertConflict()->assertJsonPath('code', 'action_stale');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_deleted_create_reference_requires_a_fresh_review_and_unauthenticated_decisions_are_denied(): void
    {
        $f = $this->fixture();
        $subject = Subject::factory()->for($f['owner'])->create();
        $turn = $this->propose($f, 'propose_lesson_create', array_replace($this->args($f, 'propose_lesson_create'), ['subject_id' => $subject->id]));
        $subject->delete();
        $before = $this->snapshot();
        $id = $turn['action']['id'];
        $this->decide($f, $id)->assertConflict()->assertJsonPath('code', 'action_stale');
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($f, $id))->assertUnauthorized();
        $this->decide($f, $id)->assertUnauthorized();
        $this->decide($f, $id, 'reject')->assertUnauthorized();
        $this->assertSame($before, $this->snapshot());
    }
}
