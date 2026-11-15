<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('workflow')]
#[Group('mysql')]
class CompactWorkCompletionTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
        $this->travelTo(CarbonImmutable::parse('2026-11-13T10:00:00Z'));
    }

    public function test_review_consent_boundary_history_and_reopening(): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $future = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 12:00:00']);
        $directFuture = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 12:00:00', 'ends_at' => '2026-11-13 13:00:00']);
        $boundary = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 10:00:00', 'ends_at' => '2026-11-13 11:00:00']);
        $base = '/api/tasks/'.$task->id;
        $this->actingAs($task->user);
        $this->postJson($base.'/complete')->assertStatus(409)->assertJsonPath('completion_review.future_sessions_count', 2);
        $review = $this->getJson($base.'/completion-review')->assertOk()->json();
        $this->postJson($base.'/complete', $review)->assertOk();
        $this->assertSame('cancelled', $future->refresh()->status->value);
        $this->assertSame('cancelled', $directFuture->refresh()->status->value);
        $this->assertNull($directFuture->actual_minutes);
        $this->assertNull($directFuture->completed_at);
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

    public function test_child_scope_reminders_and_atomic_failure(): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $other = Subtask::factory()->for($task)->create();
        $sessions = collect([$child, $other])->map(fn ($c) => StudySession::factory()->for($task)->create(['subtask_id' => $c->id, 'starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 12:00:00']));
        $reminder = Reminder::factory()->for($task->user)->create(['study_session_id' => $sessions[0]->id]);
        $elapsed = collect(['09:00:00', '10:00:00'])->map(fn ($start) => StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-11-13 '.$start, 'ends_at' => '2026-11-13 11:00:00']));
        $before = $elapsed->map(fn ($session) => $session->refresh()->getAttributes())->all();
        $base = '/api/tasks/'.$task->id.'/subtasks/'.$child->id;
        $this->actingAs($task->user);
        $review = $this->getJson($base.'/completion-review')->assertJsonPath('future_sessions_count', 1)->json();
        $this->postJson($base.'/complete', $review)->assertOk();
        $this->assertSame('cancelled', $sessions[0]->refresh()->status->value);
        $this->assertNull($sessions[0]->actual_minutes);
        $this->assertNull($sessions[0]->completed_at);
        $this->assertSame('cancelled', $reminder->refresh()->status->value);
        $this->assertSame('planned', $sessions[1]->refresh()->status->value);
        $this->assertSame('pending', $task->refresh()->status->value);
        $this->assertSame('pending', $other->refresh()->status->value);
        $this->assertSame($before, $elapsed->map(fn ($session) => $session->refresh()->getAttributes())->all());
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

    public function test_session_completion_is_independent_by_default_and_combined_action_uses_linked_child(): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $source = StudySession::factory()->for($task)->create(['subtask_id' => $child->id]);
        $this->actingAs($task->user);
        $this->postJson('/api/study-sessions/'.$source->id.'/complete', ['actual_minutes' => 12])->assertOk();
        $this->assertSame('pending', $child->refresh()->status->value);
        $this->assertSame('pending', $task->refresh()->status->value);
        $source = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-11-13 10:00:00', 'ends_at' => '2026-11-13 11:00:00']);
        $future = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 12:00:00']);
        $base = '/api/study-sessions/'.$source->id;
        $review = $this->getJson($base.'/completion-review')->assertOk()->assertJsonPath('work_type', 'subtask')->json('completion_review');
        $this->postJson($base.'/complete', ['actual_minutes' => 17, 'complete_work' => true])->assertStatus(409);
        $this->assertSame('planned', $source->refresh()->status->value);
        $this->postJson($base.'/complete', ['actual_minutes' => 17, 'complete_work' => true, ...$review])->assertStatus(422);
        $this->postJson($base.'/complete', ['actual_minutes' => 17, 'complete_work' => true, 'completion_token' => $review['completion_token']])->assertOk();
        $this->assertSame('completed', $child->refresh()->status->value);
        $this->assertSame('pending', $task->refresh()->status->value);
        $this->assertSame('cancelled', $future->refresh()->status->value);
        $this->assertSame('completed', $source->refresh()->status->value);
        $this->assertNull($future->actual_minutes);
        $this->assertNull($future->completed_at);
        $this->assertSame(17, $source->refresh()->actual_minutes);
        $this->getJson($base.'/completion-review')->assertJsonPath('can_complete_work', false);
    }

    public function test_combined_task_completion_rolls_back_on_stale_review_and_session_save_failure(): void
    {
        $task = Task::factory()->create();
        $source = StudySession::factory()->for($task)->create();
        $base = '/api/study-sessions/'.$source->id;
        $this->actingAs($task->user);
        $review = $this->getJson($base.'/completion-review')->json('completion_review');
        $future = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 11:00:00', 'ends_at' => '2026-11-13 12:00:00']);
        $this->postJson($base.'/complete', ['actual_minutes' => 23, 'complete_work' => true, 'completion_token' => $review['completion_token']])->assertStatus(409);
        $this->assertSame('planned', $source->refresh()->status->value);
        $this->assertSame('pending', $task->refresh()->status->value);
        $review = $this->getJson($base.'/completion-review')->json('completion_review');
        StudySession::updating(function ($session) use ($source) {
            if ($session->id === $source->id) {
                throw new \RuntimeException('source save failed');
            }
        });
        $this->postJson($base.'/complete', ['actual_minutes' => 23, 'complete_work' => true, 'completion_token' => $review['completion_token']])->assertServerError();
        $this->assertSame('pending', $task->refresh()->status->value);
        $this->assertSame('planned', $future->refresh()->status->value);
        $this->assertNull($source->refresh()->actual_minutes);
    }

    public function test_combined_task_completion_and_completed_work_capability(): void
    {
        $task = Task::factory()->create();
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-11-13 10:00:00', 'ends_at' => '2026-11-13 11:00:00']);
        $this->actingAs($task->user);
        $base = '/api/study-sessions/'.$source->id;
        $this->travelTo(CarbonImmutable::parse('2026-11-13T09:59:59.999999Z'));
        $this->postJson($base.'/complete', ['actual_minutes' => 5, 'complete_work' => true])->assertConflict();
        $this->assertSame('pending', $task->refresh()->status->value);
        $this->assertSame('planned', $source->refresh()->status->value);
        $this->assertNull($source->actual_minutes);
        $this->travelTo(CarbonImmutable::parse('2026-11-13T10:00:00Z'));
        $this->postJson($base.'/complete', ['actual_minutes' => 5, 'complete_work' => true])->assertOk();
        $this->assertSame('completed', $task->refresh()->status->value);
        $this->assertSame('completed', $source->refresh()->status->value);
        $this->assertSame(5, $source->actual_minutes);
        $source = StudySession::factory()->for($task)->create();
        $base = '/api/study-sessions/'.$source->id;
        $this->getJson($base.'/completion-review')->assertJsonPath('can_complete_work', false);
        $this->postJson($base.'/complete', ['actual_minutes' => 7])->assertOk();
        $this->assertSame(7, $source->refresh()->actual_minutes);
    }
}
