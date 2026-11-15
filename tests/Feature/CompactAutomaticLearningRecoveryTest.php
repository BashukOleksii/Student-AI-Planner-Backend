<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\StudySession;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithLearningRecovery;
use Tests\TestCase;

#[Group('workflow')]
#[Group('mysql')]
class CompactAutomaticLearningRecoveryTest extends TestCase
{
    use DatabaseTruncation, InteractsWithLearningRecovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeRecovery();
    }

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
}
