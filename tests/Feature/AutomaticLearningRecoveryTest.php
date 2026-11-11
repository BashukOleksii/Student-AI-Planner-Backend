<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\Tools\RecoveryReadTools;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithLearningRecovery;
use Tests\TestCase;

/** Deferred disposable-MySQL verification of the automatic review workflow, not a live scheduler test. */
#[Group('mysql')]
#[Group('recovery')]
class AutomaticLearningRecoveryTest extends TestCase
{
    use DatabaseTruncation, InteractsWithLearningRecovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeRecovery();
    }

    #[Group('smoke')]
    public function test_detection_has_no_business_writes_and_confirmation_is_owned_and_replayable(): void
    {
        $f = $this->fixture();
        $before = $this->business();
        $this->assertSame('created', $f['service']->detect($f['owner']->id));
        $row = $this->pending($f['owner']);
        $this->assertSame('create_plan', $row->kind);
        $this->assertSame($before, $this->business());
        $this->assertSame('unchanged', $f['service']->detect($f['owner']->id));
        $this->assertDatabaseCount('recovery_suggestions', 1);
        $path = '/api/planning/recovery-suggestions/'.$row->id;
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertStringNotContainsString('protected_token', $this->getJson($path)->getContent());
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web')->postJson($path.'/confirm', ['accept_partial' => 'bad'])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($f['owner'], 'web');
        $receipt = $this->postJson($path.'/confirm')->assertOk()->assertJsonPath('data.status', 'applied')->json('data.receipt');
        $after = $this->business();
        $this->assertSame($receipt, $this->postJson($path.'/confirm')->assertOk()->assertJsonPath('replayed', true)->json('data.receipt'));
        $this->assertSame($after, $this->business());
        $this->assertDatabaseCount('study_sessions', 1);
        $this->assertNull(StudySession::firstOrFail()->actual_minutes);
        $this->assertSame('pending', $f['task']->fresh()->status->value);
    }

    public function test_elapsed_planned_outcome_stays_unknown_and_only_explicit_miss_enables_recovery(): void
    {
        $f = $this->fixture();
        $session = StudySession::factory()->for($f['task'])->create(['starts_at' => '2026-10-18 10:00:00', 'ends_at' => '2026-10-18 11:00:00']);
        $this->assertSame('created', $f['service']->detect($f['owner']->id));
        $row = $this->pending($f['owner']);
        $this->assertSame('decision_required', $row->kind);
        $this->postJson('/api/planning/recovery-suggestions/'.$row->id.'/confirm')->assertUnprocessable()->assertJsonPath('code', 'decision_required');
        $this->assertSame('planned', $session->fresh()->status->value);
        $this->assertNull($session->actual_minutes);
        $this->postJson('/api/study-sessions/'.$session->id.'/miss')->assertOk();
        $this->assertSame('created', $f['service']->detect($f['owner']->id));
        $this->assertSame('create_plan', $this->pending($f['owner'])->kind);
        $this->assertSame('invalidated', $row->fresh()->status);
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_deadline_change_invalidates_a_review_without_writing_sessions(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        app(TaskService::class)->update($f['owner'], $f['task'], ['deadline_at' => '2026-10-22T17:00:00Z']);
        $before = $this->business();
        $this->postJson('/api/planning/recovery-suggestions/'.$row->id.'/confirm')->assertConflict()->assertJsonPath('code', 'suggestion_stale');
        $this->assertSame('invalidated', $row->fresh()->status);
        $this->assertNull($row->receipt);
        $this->assertSame($before, $this->business());
    }

    public function test_academic_conflict_proposes_replanning_and_only_confirmation_cancels_obsolete_reminders(): void
    {
        $f = $this->fixture();
        $session = StudySession::factory()->for($f['task'])->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        $reminder = app(ReminderService::class)->create($f['owner'], ['study_session_id' => $session->id, 'trigger_at' => '2026-10-19T08:45:00Z', 'message' => 'Study']);
        Lesson::factory()->for($f['owner'])->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        $before = $this->business();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $this->assertSame('replan', $row->kind);
        $this->assertSame($before, $this->business());
        $this->postJson('/api/planning/recovery-suggestions/'.$row->id.'/confirm')->assertOk();
        $this->assertSame('rescheduled', $session->fresh()->status->value);
        $this->assertSame('cancelled', $reminder->fresh()->status->value);
        $this->assertDatabaseCount('study_sessions', 2);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_unplaced_replanning_requires_both_explicit_consents(): void
    {
        $f = $this->fixture();
        app(TaskService::class)->update($f['owner'], $f['task'], ['deadline_at' => '2026-10-19T10:00:00Z']);
        $session = StudySession::factory()->for($f['task'])->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        Lesson::factory()->for($f['owner'])->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $this->assertSame('replan', $row->kind);
        $path = '/api/planning/recovery-suggestions/'.$row->id.'/confirm';
        $this->postJson($path)->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        $this->postJson($path, ['accept_partial' => true])->assertUnprocessable()->assertJsonValidationErrors('accept_unplaced_cancellation');
        $this->assertSame('planned', $session->fresh()->status->value);
        $this->postJson($path, ['accept_partial' => true, 'accept_unplaced_cancellation' => true])->assertOk();
        $this->assertSame('cancelled', $session->fresh()->status->value);
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_expiry_rejection_and_explicit_refresh_do_not_create_duplicate_reviews_or_sessions(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        DB::table('recovery_suggestions')->where('id', $row->id)->update(['expires_at' => now('UTC')->subSecond()]);
        $this->postJson('/api/planning/recovery-suggestions/'.$row->id.'/confirm')->assertStatus(410);
        $this->assertSame('unchanged', $f['service']->detect($f['owner']->id));
        $child = $this->postJson('/api/planning/recovery-suggestions/'.$row->id.'/refresh')->assertCreated()->json('data.id');
        $this->assertSame($child, $this->postJson('/api/planning/recovery-suggestions/'.$row->id.'/refresh')->assertOk()->json('data.id'));
        $this->postJson('/api/planning/recovery-suggestions/'.$child.'/reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('unchanged', $f['service']->detect($f['owner']->id));
        $this->assertDatabaseCount('recovery_suggestions', 2);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_ai_reads_cannot_apply_or_persist_reviews_and_unknown_estimates_are_not_zero(): void
    {
        $f = $this->fixture();
        app(TaskService::class)->update($f['owner'], $f['task'], ['estimated_minutes' => null]);
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $this->assertSame('warning', $row->kind);
        $this->assertNotEmpty($row->review['unknown_duration_work']);
        $before = $this->business();
        $reviews = DB::table('recovery_suggestions')->get()->toJson();
        $result = app(RecoveryReadTools::class)->execute($f['owner'], 'get_recovery_suggestion', ['suggestion_id' => $row->id]);
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['data']['actionable']);
        $this->assertStringNotContainsString('protected_token', json_encode($result));
        $this->assertSame($before, $this->business());
        $this->assertSame($reviews, DB::table('recovery_suggestions')->get()->toJson());
    }

    public function test_review_created_inside_open_availability_remains_confirmable_with_its_server_cutoff(): void
    {
        $f = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T09:10:00Z'));
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $this->assertSame('2026-10-19T09:25:00.000000Z', $row->review['earliest_proposed_start_at']);
        $this->assertGreaterThanOrEqual('2026-10-19T09:25:00Z', $row->review['new_work_plan']['sessions'][0]['starts_at']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T09:11:00Z'));
        $this->postJson('/api/planning/recovery-suggestions/'.$row->id.'/confirm')->assertOk();
        $this->assertSame('2026-10-19 09:25:00', StudySession::firstOrFail()->getRawOriginal('starts_at'));
    }

    public function test_strict_consent_and_unexpected_writer_failure_preserve_pending_review_and_business_state(): void
    {
        $f = $this->fixture();
        $f['service']->detect($f['owner']->id);
        $row = $this->pending($f['owner']);
        $path = '/api/planning/recovery-suggestions/'.$row->id.'/confirm';
        $this->mock(StudyPlanPersistenceService::class)->shouldReceive('confirmWithinTransaction')->once()
            ->andReturnUsing(function () use ($f) {
                StudySession::factory()->for($f['task'])->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
                throw new \RuntimeException('SQL private writer failure');
            });
        foreach ([null, 1, 'true', '', []] as $value) {
            $this->postJson($path, ['accept_partial' => $value])->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        }
        $before = $this->business();
        $response = $this->postJson($path)->assertStatus(500)->assertJsonPath('code', 'recovery_failed');
        $this->assertStringNotContainsString('SQL', $response->getContent());
        $this->assertSame($before, $this->business());
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertNull($row->fresh()->receipt);
    }

    public function test_corrupt_owned_session_cannot_disclose_a_foreign_task_in_diagnostics(): void
    {
        $f = $this->fixture();
        $foreign = Task::factory()->for(User::factory()->create())->create(['title' => 'Private foreign learning work']);
        $session = StudySession::factory()->for($f['task'])->create(['starts_at' => '2026-10-18 09:00:00', 'ends_at' => '2026-10-18 10:00:00']);
        DB::table('study_sessions')->where('id', $session->id)->update(['task_id' => $foreign->id]);
        $before = $this->business();
        $response = $this->getJson('/api/planning/recovery/diagnostics?start_date=2026-10-19&end_date=2026-10-25')
            ->assertStatus(500)->assertJsonPath('code', 'recovery_failed');
        $this->assertStringNotContainsString($foreign->title, $response->getContent());
        $this->assertSame($before, $this->business());
        $counts = $f['service']->scan(1, 7, $f['owner']->id);
        $this->assertSame(1, $counts['failed']);
        $this->assertDatabaseCount('recovery_suggestions', 0);
        $this->assertSame($before, $this->business());
    }
}
