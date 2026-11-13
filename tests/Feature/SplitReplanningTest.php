<?php

namespace Tests\Feature;

use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class SplitReplanningTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13T08:00:00Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 120]);
        $window = StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '11:00:00']);
        $preference = PlanningPreference::factory()->for($owner)->create(['min_session_minutes' => 30, 'max_session_minutes' => 120, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null]);
        $this->actingAs($owner);
        $preview = $this->postJson('/api/planning/study-plans/preview', ['task_id' => $task->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-16'])->assertOk()->json('data');
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $preview['preview_token']])->assertSuccessful();
        $source = StudySession::where('task_id', $task->id)->sole();
        $window->update(['ends_at' => '10:00:00']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '14:00:00', 'ends_at' => '15:00:00']);
        $preference->update(['max_session_minutes' => 60]);

        return [$owner, $task, $source, $preference];
    }

    private function preview(): array
    {
        return $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-11-16', 'end_date' => '2026-11-16'])->assertOk()->json('data');
    }

    public function test_split_requires_separate_consent_preserves_tree_and_cannot_be_replayed(): void
    {
        [$owner, $task, $source] = $this->fixture();
        $preview = $this->preview();
        $row = $preview['session_results'][0];
        $this->assertNull($row['replacement']);
        $this->assertCount(2, $row['replacements']);
        $this->assertSame([3600, 3600], array_column($row['replacements'], 'duration_seconds'));
        $body = ['preview_token' => $preview['preview_token']];
        $this->postJson('/api/planning/replanning/confirm', $body)->assertUnprocessable()->assertJsonValidationErrors('accept_split_replacements');
        $this->assertDatabaseCount('study_sessions', 1);
        $body['accept_split_replacements'] = true;
        $result = $this->postJson('/api/planning/replanning/confirm', $body)->assertOk()->json('data');
        $this->assertCount(2, $result['replacement_sessions']);
        $this->assertSame(2, $result['summary']['created_replacements']);
        $this->assertSame('rescheduled', $source->fresh()->status->value);
        $children = $source->fresh()->rescheduledToSessions;
        $this->assertCount(2, $children);
        $migration = require database_path('migrations/2026_11_13_000001_allow_multiple_study_session_successors.php');
        try {
            $migration->down();
            $this->fail('Rollback silently discarded split history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('split history exists', $exception->getMessage());
        }
        $this->assertDatabaseCount('study_sessions', 3);
        $this->assertSame(7200, $children->sum(fn ($s) => $s->ends_at->timestamp - $s->starts_at->timestamp));
        $this->assertSame($source->id, $result['replacement_groups'][0]['original_session_id']);
        $this->postJson('/api/planning/replanning/confirm', $body)->assertConflict();
        $this->assertDatabaseCount('study_sessions', 3);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '16:00:00', 'ends_at' => '17:00:00']);
        $child = $children->first();
        $this->postJson('/api/study-sessions/'.$child->id.'/reschedule', ['starts_at' => '2026-11-16T16:00:00Z', 'ends_at' => '2026-11-16T17:00:00Z'])->assertSuccessful();
        $this->assertSame('rescheduled', $child->fresh()->status->value);
        $this->assertCount(1, $child->fresh()->rescheduledToSessions);
        $this->assertSame('planned', $children->last()->fresh()->status->value);
        $this->preview(); // validates the complete tree, including the sibling branch
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/planning/replanning/confirm', $body)->assertUnprocessable();
        $this->assertDatabaseCount('study_sessions', 4);
    }

    public function test_split_is_capped_by_remaining_effort_and_needs_partial_consent(): void
    {
        [$owner, $task, $source] = $this->fixture();
        $task->update(['estimated_minutes' => 90]);
        $preview = $this->preview();
        $this->assertSame([3600, 1800], array_column($preview['session_results'][0]['replacements'], 'duration_seconds'));
        $body = ['preview_token' => $preview['preview_token'], 'accept_split_replacements' => true];
        $this->postJson('/api/planning/replanning/confirm', $body)->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        $this->assertDatabaseCount('study_sessions', 1);
        $this->postJson('/api/planning/replanning/confirm', [...$body, 'accept_partial' => true])->assertOk()
            ->assertJsonPath('data.summary.untransferred_seconds', 1800);
        $this->assertSame(5400, $source->fresh()->rescheduledToSessions->sum(fn ($s) => $s->ends_at->timestamp - $s->starts_at->timestamp));
    }

    public function test_preference_change_invalidates_split_review_without_retiring_source(): void
    {
        [$owner, $task, $source, $preference] = $this->fixture();
        $preview = $this->preview();
        $preference->update(['min_session_minutes' => 90, 'max_session_minutes' => 120]);
        $this->postJson('/api/planning/replanning/confirm', ['preview_token' => $preview['preview_token'], 'accept_split_replacements' => true])->assertConflict();
        $this->assertSame('planned', $source->fresh()->status->value);
        $this->assertDatabaseCount('study_sessions', 1);
    }
}
