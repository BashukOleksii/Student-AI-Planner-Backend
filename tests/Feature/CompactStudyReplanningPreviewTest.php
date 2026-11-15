<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningCandidateEvaluationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsBatchedReads;
use Tests\TestCase;

#[Group('integration')]
#[Group('mysql')]
class CompactStudyReplanningPreviewTest extends TestCase
{
    use AssertsBatchedReads;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_valid_future_session_remains_protected_despite_ordinary_c10_self_conflict(): void
    {
        [$user, $task, $source] = $this->fixture();
        $task->update(['estimated_minutes' => 60]);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 60, 'max_weekly_study_minutes' => 60]);
        $this->assertNotSame([], app(PlanningCandidateEvaluationService::class)->evaluate($user, ['start' => strtotime('2026-10-19T10:00:00Z'), 'end' => strtotime('2026-10-19T11:00:00Z')], $task->id));
        $snapshot = $this->snapshot();
        $data = $this->preview();
        $this->assertSame('valid', $data['session_results'][0]['classification']);
        $this->assertNull($data['session_results'][0]['replacement']);
        $this->assertSame([], $data['session_results'][0]['reason_codes']);
        $this->assertTrue($data['summary']['no_changes_needed']);
        $this->assertSame([], $data['released_session_ids']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_lesson_change_produces_exact_replacement_and_repeated_select_only_snapshots(): void
    {
        [$user, $task, $source] = $this->fixture();
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 10:30:00', 'ends_at' => '2026-10-19 11:30:00']);
        Reminder::factory()->for($user)->create(['study_session_id' => $source->id]);
        $snapshot = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->preview();
        $again = $this->preview();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertNotSame($data['preview_token'], $again['preview_token']);
        unset($data['preview_token'], $again['preview_token']);
        $this->assertSame($data, $again);
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame('2026-10-19T08:00:00.500000Z', $data['as_of_utc']);
        $row = $data['session_results'][0];
        $this->assertSame($source->id, $row['study_session_id']);
        $this->assertSame($task->id, $row['task_id']);
        $this->assertSame('requires_replanning', $row['classification']);
        $this->assertSame(['outside_available_study_time', 'overlapping_break_anchor'], $row['reason_codes']);
        $this->assertSame(['starts_at' => '2026-10-19T09:00:00Z', 'ends_at' => '2026-10-19T10:00:00Z', 'duration_seconds' => 3600], $row['replacement']);
        $this->assertSame(0, $row['untransferred_seconds']);
        $this->assertSame(3600, $data['work_results'][0]['unallocated_after_replacements']);
        $this->assertSame('2026-10-19T08:15:00Z', $data['expires_at']);
        $this->assertArrayNotHasKey('effort', $row);
        $this->assertArrayNotHasKey('user_id', $row);
        $this->assertSame(StudySessionStatus::Planned, $source->fresh()->status);
    }

    #[DataProvider('invalidInput')]
    public function test_request_has_strict_dates_identifiers_and_body_only_fields(array $body, array $errors): void
    {
        $this->fixture();
        $snapshot = $this->snapshot();
        $this->call('POST', '/api/planning/replanning/preview', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['start_date' => '2026-10-19', 'end_date' => '2026-10-19', ...$body], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function invalidInput(): array
    {
        return [0 => [['start_date' => ' 2026-10-19'], [0 => 'start_date']]];
    }

    public function test_query_parameters_cannot_supply_a_post_body_and_31_dates_pass(): void
    {
        $this->fixture();
        $this->postJson('/api/planning/replanning/preview?start_date=2026-10-19&end_date=2026-10-19')->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-11-18'])->assertOk();
    }

    #[DataProvider('constraintChange')]
    public function test_current_constraints_detect_invalid_sources(string $kind, string $code): void
    {
        [$user, $task] = $this->fixture();
        switch ($kind) {
            case 'removed_availability':
                $user->studyAvailabilityWindows()->delete();
                break;
            case 'reduced_availability':
                $user->studyAvailabilityWindows()->update(['ends_at' => '10:30:00']);
                break;
            case 'maximum':
                PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30]);
                break;
            case 'minimum':
                PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 61]);
                break;
            case 'break':
                PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 16]);
                Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 09:45:00']);
                break;
            case 'daily':
            case 'weekly':
                PlanningPreference::factory()->for($user)->create([$kind === 'daily' ? 'max_daily_study_minutes' : 'max_weekly_study_minutes' => 60]);
                StudySession::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-19 08:00:00', 'starts_at' => '2026-10-19 07:00:00', 'ends_at' => '2026-10-19 08:00:00', 'actual_minutes' => 1]);
                break;
            case 'deadline':
                $task->update(['deadline_at' => '2026-10-19 10:30:00']);
                break;
        }
        $snapshot = $this->snapshot();
        $data = $this->preview();
        $this->assertSame('requires_replanning', $data['session_results'][0]['classification']);
        $this->assertContains($code, $data['session_results'][0]['reason_codes']);
        $this->assertSame($snapshot, $this->snapshot());
        if (in_array($kind, ['removed_availability', 'daily', 'weekly', 'minimum'], true)) {
            $this->assertNull($data['session_results'][0]['replacement']);
            $this->assertSame(3600, $data['summary']['untransferred_seconds']);
        }
        if ($kind === 'break') {
            $this->assertSame('2026-10-19T10:01:00Z', $data['session_results'][0]['replacement']['starts_at']);
        }
    }

    public static function constraintChange(): array
    {
        return [0 => ['removed_availability', 'outside_available_study_time']];
    }

    #[DataProvider('ineligibleWork')]
    public function test_work_ineligibility_is_distinct_from_source_corruption(string $kind, string $code): void
    {
        [$user, $task, $source] = $this->fixture();
        switch ($kind) {
            case 'task_completed':
                $task->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 08:00:00']);
                break;
            case 'task_deleted':
                $task->delete();
                break;
            case 'child_completed':
            case 'child_deleted':
                $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
                $source->update(['subtask_id' => $child->id]);
                if ($kind === 'child_completed') {
                    $child->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 08:00:00']);
                } else {
                    $child->delete();
                }
                break;
            case 'decomposed':
                Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
                break;
            case 'missing_estimate':
                $task->update(['estimated_minutes' => null]);
                break;
            case 'expired':
                $task->update(['deadline_at' => '2026-10-19 08:00:00']);
                break;
            case 'credit_satisfied':
                StudySession::factory()->for($task)->create(['starts_at' => '2026-10-18 09:00:00', 'ends_at' => '2026-10-18 10:00:00', 'status' => 'completed', 'completed_at' => '2026-10-18 10:00:00', 'actual_minutes' => 120]);
                break;
        }
        $snapshot = $this->snapshot();
        $row = $this->preview()['session_results'][0];
        $this->assertSame('cannot_replan', $row['classification']);
        $this->assertContains($code, $row['reason_codes']);
        $this->assertNull($row['replacement']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function ineligibleWork(): array
    {
        return [0 => ['task_completed', 'completed_task']];
    }

    #[DataProvider('nonFutureClocks')]
    public function test_past_or_running_plans_are_diagnosed_without_missed_inference(string $clock): void
    {
        [, , $source] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse($clock));
        $snapshot = $this->snapshot();
        $row = $this->preview()['session_results'][0];
        $this->assertSame('cannot_replan', $row['classification']);
        $this->assertContains('session_start_not_future', $row['reason_codes']);
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame(StudySessionStatus::Planned, $source->fresh()->status);
    }

    public static function nonFutureClocks(): array
    {
        return [0 => ['2026-10-19T10:00:00Z']];
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => 120]);
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        foreach (range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);
        }
        $this->actingAs($user);

        return [$user, $task, $source];
    }

    private function preview(array $scope = []): array
    {
        return $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19', ...$scope])->assertOk()->json('data');
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $result[$table] = $rows;
        }
        ksort($result);

        return $result;
    }
}
