<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkCompletionTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13T10:00:00Z'));
    }

    public function test_review_consent_boundary_history_and_reopening(): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $future = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 12:00:00']);
        $boundary = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 10:00:00', 'ends_at' => '2026-11-13 11:00:00']);
        $base = '/api/tasks/'.$task->id;
        $this->actingAs($task->user);
        $this->postJson($base.'/complete')->assertStatus(409)->assertJsonPath('completion_review.future_sessions_count', 1);
        $review = $this->getJson($base.'/completion-review')->assertOk()->json();
        $this->postJson($base.'/complete', $review)->assertOk();
        $this->assertSame('cancelled', $future->refresh()->status->value);
        $this->assertSame('planned', $boundary->refresh()->status->value);
        $this->assertSame('pending', $child->refresh()->status->value);
        $this->postJson($base.'/complete')->assertOk();
        $this->postJson($base.'/reopen')->assertOk();
        $this->assertSame('cancelled', $future->refresh()->status->value);
        $this->assertNull($future->actual_minutes);
    }

    public function test_exact_set_and_zero_reviews_are_rechecked(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user);
        $base = '/api/tasks/'.$task->id;
        $empty = $this->getJson($base.'/completion-review')->json();
        $session = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 12:00:00']);
        $this->postJson($base.'/complete', $empty)->assertStatus(409);
        $review = $this->getJson($base.'/completion-review')->json();
        $session->update(['status' => 'cancelled']);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 12:00:00']);
        $this->postJson($base.'/complete', $review)->assertStatus(409);
        $this->assertSame('pending', $task->refresh()->status->value);
        $review = $this->getJson($base.'/completion-review')->json();
        StudySession::where('task_id', $task->id)->update(['status' => 'cancelled']);
        $this->postJson($base.'/complete', $review)->assertStatus(409);
    }

    public function test_empty_completion_and_foreign_review(): void
    {
        $task = Task::factory()->create();
        $this->actingAs(User::factory()->create())->getJson('/api/tasks/'.$task->id.'/completion-review')->assertNotFound();
        $this->actingAs($task->user)->postJson('/api/tasks/'.$task->id.'/complete')->assertOk();
    }

    public function test_child_scope_reminders_and_atomic_failure(): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $other = Subtask::factory()->for($task)->create();
        $sessions = collect([$child, $other])->map(fn ($c) => StudySession::factory()->for($task)->create([
            'subtask_id' => $c->id, 'starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 12:00:00',
        ]));
        $reminder = Reminder::factory()->for($task->user)->create(['study_session_id' => $sessions[0]->id]);
        $base = '/api/tasks/'.$task->id.'/subtasks/'.$child->id;
        $this->actingAs($task->user);
        $review = $this->getJson($base.'/completion-review')->assertJsonPath('future_sessions_count', 1)->json();
        $this->postJson($base.'/complete', $review)->assertOk();
        $this->assertSame('cancelled', $sessions[0]->refresh()->status->value);
        $this->assertSame('cancelled', $reminder->refresh()->status->value);
        $this->assertSame('planned', $sessions[1]->refresh()->status->value);
        $this->assertSame('pending', $task->refresh()->status->value);
        $this->assertSame('pending', $other->refresh()->status->value);
    }

    public function test_write_failure_rolls_back_sessions_work_reminders_and_scan_generation(): void
    {
        $task = Task::factory()->create();
        $session = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 12:00:00']);
        $this->actingAs($task->user);
        $base = '/api/tasks/'.$task->id;
        $review = $this->getJson($base.'/completion-review')->json();
        $version = DB::table('recovery_scan_states')->where('user_id', $task->user_id)->value('requested_version');
        Task::updating(function () {
            throw new \RuntimeException('injected failure');
        });
        $this->postJson($base.'/complete', $review)->assertServerError();
        $this->assertSame('planned', $session->refresh()->status->value);
        $this->assertSame('pending', $task->refresh()->status->value);
        $this->assertSame($version, DB::table('recovery_scan_states')->where('user_id', $task->user_id)->value('requested_version'));
    }
}
