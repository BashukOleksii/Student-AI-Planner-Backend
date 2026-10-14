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
use App\Services\Planning\PlanningWorkSelectionService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningPreviewService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReschedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudyReplanningPreviewTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_authentication_precedes_validation(): void
    {
        $this->postJson('/api/planning/replanning/preview', [])->assertUnauthorized();
        $this->assertSame('/api/planning/replanning/preview', route('planning.replanning.preview', [], false));
    }

    public function test_valid_future_session_remains_protected_despite_ordinary_c10_self_conflict(): void
    {
        [$user, $task, $source] = $this->fixture();
        $task->update(['estimated_minutes' => 60]);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 60, 'max_weekly_study_minutes' => 60]);
        $this->assertNotSame([], app(PlanningCandidateEvaluationService::class)->evaluate($user,
            ['start' => strtotime('2026-10-19T10:00:00Z'), 'end' => strtotime('2026-10-19T11:00:00Z')], $task->id));
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
        $this->call('POST', '/api/planning/replanning/preview', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['start_date' => '2026-10-19', 'end_date' => '2026-10-19', ...$body], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function invalidInput(): array
    {
        return [
            [['start_date' => ' 2026-10-19'], ['start_date']], [['start_date' => '2026-02-30'], ['start_date']],
            [['start_date' => '2026-10-19T00:00:00Z'], ['start_date']], [['end_date' => '2026-10-18'], ['end_date']],
            [['end_date' => '2026-11-19'], ['end_date']], [['task_id' => '1'], ['task_id']], [['task_id' => 1.0], ['task_id']],
            [['task_id' => true], ['task_id']], [['task_id' => 0], ['task_id']], [['task_id' => []], ['task_id']], [['task_id' => ''], ['task_id']],
            [['subtask_id' => 1], ['task_id']], [['subtask_id' => '1', 'task_id' => 1], ['subtask_id']],
            [['user_id' => null, 'sessions' => [], 'status' => null, 'session_ids' => [], 'replacements' => []], ['user_id', 'sessions', 'status', 'session_ids', 'replacements']],
        ];
    }

    public function test_query_parameters_cannot_supply_a_post_body_and_31_dates_pass(): void
    {
        $this->fixture();
        $this->postJson('/api/planning/replanning/preview?start_date=2026-10-19&end_date=2026-10-19')->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-11-18'])->assertOk();
    }

    public function test_foreign_and_mismatched_explicit_scope_is_generic_404_and_foreign_sessions_never_appear(): void
    {
        [$user, $task] = $this->fixture();
        $foreign = Task::factory()->create(['estimated_minutes' => 60]);
        $foreignChild = Subtask::factory()->for($foreign)->create();
        StudySession::factory()->for($foreign)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $other = Task::factory()->for($user)->create();
        $otherChild = Subtask::factory()->for($other)->create();
        foreach ([['task_id' => $foreign->id], ['task_id' => $task->id, 'subtask_id' => $foreignChild->id],
            ['task_id' => $task->id, 'subtask_id' => $otherChild->id], ['task_id' => 999999]] as $scope) {
            $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19', ...$scope])
                ->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        }
        $this->assertCount(1, $this->preview()['session_results']);
    }

    #[DataProvider('constraintChange')]
    public function test_current_constraints_detect_invalid_sources(string $kind, string $code): void
    {
        [$user, $task] = $this->fixture();
        switch ($kind) {
            case 'removed_availability': $user->studyAvailabilityWindows()->delete();
                break;
            case 'reduced_availability': $user->studyAvailabilityWindows()->update(['ends_at' => '10:30:00']);
                break;
            case 'maximum': PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30]);
                break;
            case 'minimum': PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 61]);
                break;
            case 'break':
                PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 16]);
                Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 09:45:00']);
                break;
            case 'daily':
            case 'weekly':
                PlanningPreference::factory()->for($user)->create([$kind === 'daily' ? 'max_daily_study_minutes' : 'max_weekly_study_minutes' => 60]);
                StudySession::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-19 08:00:00',
                    'starts_at' => '2026-10-19 07:00:00', 'ends_at' => '2026-10-19 08:00:00', 'actual_minutes' => 1]);
                break;
            case 'deadline': $task->update(['deadline_at' => '2026-10-19 10:30:00']);
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
        return [['removed_availability', 'outside_available_study_time'], ['reduced_availability', 'outside_available_study_time'],
            ['maximum', 'above_maximum_session_duration'], ['minimum', 'below_minimum_session_duration'], ['break', 'insufficient_break_before'],
            ['daily', 'daily_study_limit_exceeded'], ['weekly', 'weekly_study_limit_exceeded'], ['deadline', 'deadline_exceeded']];
    }

    #[DataProvider('ineligibleWork')]
    public function test_work_ineligibility_is_distinct_from_source_corruption(string $kind, string $code): void
    {
        [$user, $task, $source] = $this->fixture();
        switch ($kind) {
            case 'task_completed': $task->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 08:00:00']);
                break;
            case 'task_deleted': $task->delete();
                break;
            case 'child_completed':
            case 'child_deleted':
                $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
                $source->update(['subtask_id' => $child->id]);
                if ($kind === 'child_completed') {
                    $child->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 08:00:00']);
                } else {
                    $child->delete();
                } break;
            case 'decomposed': Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
                break;
            case 'missing_estimate': $task->update(['estimated_minutes' => null]);
                break;
            case 'expired': $task->update(['deadline_at' => '2026-10-19 08:00:00']);
                break;
            case 'credit_satisfied':
                StudySession::factory()->for($task)->create(['starts_at' => '2026-10-18 09:00:00', 'ends_at' => '2026-10-18 10:00:00',
                    'status' => 'completed', 'completed_at' => '2026-10-18 10:00:00', 'actual_minutes' => 120]);
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
        return [['task_completed', 'completed_task'], ['task_deleted', 'deleted_task'], ['child_completed', 'completed_subtask'],
            ['child_deleted', 'deleted_subtask'], ['decomposed', 'subtasks_authoritative'], ['missing_estimate', 'missing_estimate'],
            ['expired', 'deadline_expired'], ['credit_satisfied', 'completed_effort_satisfies_estimate']];
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
        return [['2026-10-19T10:00:00Z'], ['2026-10-19T10:00:00.000001Z'], ['2026-10-19T10:30:00Z'], ['2026-10-19T11:00:00Z'], ['2026-10-20T08:00:00Z']];
    }

    #[DataProvider('historicalStatuses')]
    public function test_terminal_session_history_is_not_proposed_as_a_predecessor(string $status): void
    {
        [, , $source] = $this->fixture();
        $source->update(['status' => $status, 'completed_at' => $status === 'completed' ? '2026-10-19 11:00:00' : null]);
        $snapshot = $this->snapshot();
        $data = $this->preview();
        $this->assertSame([], $data['session_results']);
        $this->assertSame([], $data['released_session_ids']);
        $this->assertTrue($data['summary']['no_changes_needed']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function historicalStatuses(): array
    {
        return [['completed'], ['missed'], ['rescheduled'], ['cancelled']];
    }

    public function test_partial_replacement_reports_original_shortfall_separately_from_total_work(): void
    {
        [$user] = $this->fixture();
        $user->studyAvailabilityWindows()->update(['starts_at' => '10:00:00', 'ends_at' => '10:30:01']);
        $data = $this->preview();
        $this->assertSame(['starts_at' => '2026-10-19T10:00:00Z', 'ends_at' => '2026-10-19T10:30:01Z', 'duration_seconds' => 1801], $data['session_results'][0]['replacement']);
        $this->assertSame(1799, $data['session_results'][0]['untransferred_seconds']);
        $this->assertSame(5400 - 1, $data['work_results'][0]['unallocated_after_replacements']);
        $this->assertSame(7200, $data['work_results'][0]['remaining_work_seconds']);
        $this->assertContains('incomplete_relocation', $data['session_results'][0]['replacement_reason_codes']);
    }

    public function test_valid_protected_reservation_and_previous_proposal_block_later_proposals(): void
    {
        [$user, $task, $source] = $this->fixture();
        $task->update(['estimated_minutes' => 180]);
        $valid = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        $second = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 60, 'preferred_break_minutes' => 15]);
        // The configured break already makes the 09:00 valid candidate conflict with the original 10:00 session.
        // Separate it by moving the protected session earlier; availability begins at 08:00.
        $valid->update(['starts_at' => '2026-10-19 08:00:01', 'ends_at' => '2026-10-19 09:00:01']);
        $user->studyAvailabilityWindows()->update(['starts_at' => '08:00:00']);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 10:30:00', 'ends_at' => '2026-10-19 12:30:00']);
        $data = $this->preview();
        $byId = collect($data['session_results'])->keyBy('study_session_id');
        $this->assertSame('valid', $byId[$valid->id]['classification']);
        $this->assertNull($byId[$valid->id]['replacement']);
        $this->assertSame(['starts_at' => '2026-10-19T12:45:00Z', 'ends_at' => '2026-10-19T13:45:00Z', 'duration_seconds' => 3600], $byId[$source->id]['replacement']);
        $this->assertSame('2026-10-19T14:00:00Z', $byId[$second->id]['replacement']['starts_at']);
        $this->assertSame(0, $data['work_results'][0]['unallocated_after_replacements']);
        $this->assertSame(2, $data['summary']['affected_sessions']);
    }

    public function test_parent_child_deadline_precedence_and_exact_end_equality(): void
    {
        [$user, $task, $source] = $this->fixture();
        $task->update(['deadline_at' => '2026-10-19 10:00:00']);
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120, 'deadline_at' => '2026-10-19 12:00:00']);
        $source->update(['subtask_id' => $child->id]);
        $data = $this->preview(['task_id' => $task->id, 'subtask_id' => $child->id]);
        $row = $data['session_results'][0];
        $this->assertSame($child->id, $row['subtask_id']);
        $this->assertSame(['deadline_exceeded'], $row['reason_codes']);
        $this->assertSame('2026-10-19T10:00:00Z', $row['replacement']['ends_at']);
        $this->assertSame(7200, $data['work_results'][0]['unallocated_before_replacements']);
        $this->assertSame(3600, $data['work_results'][0]['unallocated_after_replacements']);
    }

    public function test_other_tasks_outside_scope_still_block_candidate_sources_and_replacements(): void
    {
        [$user, $task] = $this->fixture();
        $other = Task::factory()->for($user)->create();
        StudySession::factory()->for($other)->create(['starts_at' => '2026-10-19 10:30:00', 'ends_at' => '2026-10-19 11:30:00']);
        $data = $this->preview(['task_id' => $task->id]);
        $this->assertCount(1, $data['session_results']);
        $this->assertContains('overlapping_break_anchor', $data['session_results'][0]['reason_codes']);
        $this->assertSame('2026-10-19T09:00:00Z', $data['session_results'][0]['replacement']['starts_at']);
    }

    #[DataProvider('corruptSources')]
    public function test_corrupted_owned_work_or_history_fails_closed_without_sensitive_details(string $kind): void
    {
        [$user, $task, $source] = $this->fixture();
        switch ($kind) {
            case 'task': $source->update(['task_id' => Task::factory()->create()->id]);
                break;
            case 'child': $source->update(['subtask_id' => Subtask::factory()->create()->id]);
                break;
            case 'self': $source->update(['rescheduled_from_session_id' => $source->id]);
                break;
            case 'foreign_ancestor':
                $ancestor = StudySession::factory()->create(['status' => 'rescheduled']);
                $source->update(['rescheduled_from_session_id' => $ancestor->id]);
                break;
            case 'wrong_ancestor':
                $ancestor = StudySession::factory()->for($task)->create();
                $source->update(['rescheduled_from_session_id' => $ancestor->id]);
                break;
            case 'successor': StudySession::factory()->for($task)->create(['rescheduled_from_session_id' => $source->id]);
                break;
            case 'foreign_successor': StudySession::factory()->create(['rescheduled_from_session_id' => $source->id]);
                break;
        }
        $snapshot = $this->snapshot();
        $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'])->assertStatus(500)
            ->assertExactJson(['message' => 'The replanning preview could not be processed.', 'classification' => 'invalid_source']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function corruptSources(): array
    {
        return [['task'], ['child'], ['self'], ['foreign_ancestor'], ['wrong_ancestor'], ['successor'], ['foreign_successor']];
    }

    public function test_query_count_is_independent_of_source_count_and_proposals_respect_one_to_one_history(): void
    {
        [$user, $task, $source] = $this->fixture();
        $task->update(['estimated_minutes' => 65535]);
        $source->update(['ends_at' => '2026-10-19 10:01:00']);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $small = $this->queryCount($user);
        StudySession::factory()->count(40)->for($task)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 10:01:00']);
        $large = $this->queryCount($user);
        $this->assertSame($small, $large);
        $this->assertLessThan(30, $large);
        $data = $this->preview();
        $this->assertSame(41, $data['summary']['proposed_replacements']);
        $this->assertSame(2460, $data['summary']['replacement_seconds']);
        $this->assertSame(41, count(array_unique(array_column($data['session_results'], 'study_session_id'))));
        $this->assertSame(41, StudySession::count());
    }

    public function test_stage_a_exclusion_is_trusted_identity_based_and_does_not_change_default_result(): void
    {
        [$user, $task, $source] = $this->fixture();
        $service = app(PlanningWorkSelectionService::class);
        $clock = CarbonImmutable::now('UTC');
        $normal = $service->select($user, $task->id);
        $projected = $service->select($user, $task->id, null, [$source->id], $clock);
        $this->assertSame(3600, $normal['work_items'][0]['effort']['future_reserved_seconds']);
        $this->assertSame(0, $projected['work_items'][0]['effort']['future_reserved_seconds']);
        $this->assertSame(7200, $projected['work_items'][0]['effort']['unallocated_work_seconds']);
        $this->assertSame($normal, $service->select($user, $task->id));
        $foreign = StudySession::factory()->create();
        $this->expectException(InvalidArgumentException::class);
        $service->select($user, $task->id, null, [$foreign->id], $clock);
    }

    public function test_stage_c_confirmation_and_stage_d_chain_remain_independently_functional(): void
    {
        [$user, $task, $source] = $this->fixture();
        app(StudySessionLifecycleService::class)->cancel($user, (string) $source->id);
        $service = app(StudyPlanPersistenceService::class);
        $preview = $service->preview($user, '2026-10-19', '2026-10-19', $task->id);
        $saved = $service->confirm($user, $preview['preview_token'])['sessions'][0];
        $transfer = app(StudySessionReschedulingService::class)->reschedule($user, (string) $saved->id, '2026-10-19T12:00:00Z', '2026-10-19T13:00:00Z');
        $data = $this->preview();
        $this->assertSame($transfer['replacement']->id, $data['session_results'][0]['study_session_id']);
        $this->assertSame('valid', $data['session_results'][0]['classification']);
        $this->assertSame($saved->id, $transfer['replacement']->rescheduled_from_session_id);
    }

    public function test_reversed_database_retrieval_does_not_change_priority_or_proposals(): void
    {
        [$user, $task, $source] = $this->fixture();
        $task->update(['priority' => 1]);
        $high = Task::factory()->for($user)->create(['priority' => 3, 'estimated_minutes' => 60]);
        $highSession = StudySession::factory()->for($high)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        $deadline = Task::factory()->for($user)->create(['priority' => 1, 'deadline_at' => '2026-10-19 17:00:00', 'estimated_minutes' => 60]);
        $deadlineSession = StudySession::factory()->for($deadline)->create(['starts_at' => '2026-10-19 14:00:00', 'ends_at' => '2026-10-19 15:00:00']);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 16:00:00']);
        $expected = $this->preview();
        $this->assertSame([$deadlineSession->id, $highSession->id, $source->id], array_column($expected['session_results'], 'study_session_id'));
        $this->assertSame('2026-10-19T09:00:00Z', $expected['session_results'][0]['replacement']['starts_at']);
        foreach ([Task::class, Subtask::class, StudySession::class, Lesson::class, StudyAvailabilityWindow::class] as $model) {
            $model::addGlobalScope('reverse_replanning_test', fn ($query) => $query->orderByDesc($query->getModel()->qualifyColumn('id')));
        }
        try {
            $actual = $this->preview();
            unset($expected['preview_token'], $actual['preview_token']);
            $this->assertSame($expected, $actual);
        } finally {
            Task::clearBootedModels();
        }
    }

    #[DataProvider('calendarBoundaries')]
    public function test_kyiv_dst_and_iso_week_boundaries_preserve_utc_seconds(string $date, string $start, string $end, string $clock): void
    {
        [$user, $task, $source] = $this->fixture();
        $user->update(['timezone' => 'Europe/Kyiv']);
        $task->update(['estimated_minutes' => 180]);
        $this->travelTo(CarbonImmutable::parse($clock));
        $source->update(['starts_at' => CarbonImmutable::parse($start)->format('Y-m-d H:i:s'), 'ends_at' => CarbonImmutable::parse($end)->format('Y-m-d H:i:s')]);
        $user->studyAvailabilityWindows()->update(['starts_at' => '00:00:00', 'ends_at' => '24:00:00']);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 180, 'max_weekly_study_minutes' => 180]);
        $body = ['start_date' => $date, 'end_date' => $date];
        $valid = $this->postJson('/api/planning/replanning/preview', $body)->assertOk()->json('data');
        $this->assertSame('valid', $valid['session_results'][0]['classification']);
        $this->assertSame(CarbonImmutable::parse($end)->getTimestamp() - CarbonImmutable::parse($start)->getTimestamp(),
            $valid['session_results'][0]['original']['duration_seconds']);
        Lesson::factory()->for($user)->create(['starts_at' => CarbonImmutable::parse($start)->addSeconds(1)->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::parse($end)->format('Y-m-d H:i:s')]);
        $snapshot = $this->snapshot();
        $data = $this->postJson('/api/planning/replanning/preview', $body)->assertOk()->json('data');
        $this->assertSame('requires_replanning', $data['session_results'][0]['classification']);
        $this->assertNotNull($data['session_results'][0]['replacement']);
        $this->assertSame('Europe/Kyiv', $data['timezone']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function calendarBoundaries(): array
    {
        return [
            ['2027-03-28', '2027-03-28T00:30:00Z', '2027-03-28T02:30:00Z', '2027-03-27T08:00:00Z'],
            ['2026-10-25', '2026-10-25T00:30:00Z', '2026-10-25T02:30:00Z', '2026-10-24T08:00:00Z'],
            ['2027-01-04', '2027-01-03T21:30:00Z', '2027-01-03T22:30:00Z', '2027-01-03T08:00:00Z'],
        ];
    }

    public function test_crossing_the_requested_boundary_is_not_an_availability_violation_and_analysis_loads_full_original(): void
    {
        [$user, $task, $source] = $this->fixture();
        $source->update(['starts_at' => '2026-10-19 23:30:00', 'ends_at' => '2026-10-20 00:30:00']);
        $user->studyAvailabilityWindows()->update(['starts_at' => '00:00:00', 'ends_at' => '24:00:00']);
        $data = $this->preview();
        $this->assertSame('valid', $data['session_results'][0]['classification']);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-10-20 01:00:00']);
        $data = $this->preview();
        $this->assertContains('overlapping_break_anchor', $data['session_results'][0]['reason_codes']);
        $this->assertSame('2026-10-19T08:00:01Z', $data['session_results'][0]['replacement']['starts_at']);
        $this->assertSame(0, $data['summary']['untransferred_seconds']);
    }

    public function test_clock_capture_is_shared_even_if_time_advances_during_source_queries(): void
    {
        [$user, , $source] = $this->fixture();
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $original = CarbonImmutable::now('UTC');
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'study_availability_windows')) {
                CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19T10:30:00Z'));
            }
        });
        $data = app(StudyReplanningPreviewService::class)->preview($user, '2026-10-19', '2026-10-19');
        $this->assertSame($original->format('Y-m-d\TH:i:s.u\Z'), $data['as_of_utc']);
        $this->assertSame('requires_replanning', $data['session_results'][0]['classification']);
        $this->assertSame(strtotime('2026-10-19T09:00:00Z'), $data['session_results'][0]['replacement']['start']);
    }

    public function test_oversubscribed_affected_sources_share_effort_without_double_release(): void
    {
        [$user, $task, $source] = $this->fixture();
        $task->update(['estimated_minutes' => 60]);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        $data = $this->preview();
        $this->assertSame(2, $data['summary']['affected_sessions']);
        $this->assertSame(1, $data['summary']['proposed_replacements']);
        $this->assertSame(1, $data['summary']['unplaced_sessions']);
        $this->assertSame(3600, $data['summary']['replacement_seconds']);
        $this->assertSame(3600, $data['summary']['untransferred_seconds']);
        $this->assertSame(0, $data['work_results'][0]['unallocated_after_replacements']);
        $this->assertContains('no_replacement_capacity', $data['session_results'][1]['replacement_reason_codes']);
    }

    public function test_already_valid_protected_workload_limits_the_relocated_part_once(): void
    {
        [$user, $task] = $this->fixture();
        $other = Task::factory()->for($user)->create(['estimated_minutes' => 60]);
        $valid = StudySession::factory()->for($other)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 60]);
        // Each existing candidate sees the whole two-hour day, so both are affected by the limit.
        // Restrict analysis to the first task: the outside-scope reservation is protected.
        $data = $this->preview(['task_id' => $task->id]);
        $this->assertSame(1, $data['summary']['affected_sessions']);
        $this->assertSame(0, $data['summary']['proposed_replacements']);
        $this->assertSame(StudySessionStatus::Planned, $valid->fresh()->status);
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

    private function queryCount(User $user): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(StudyReplanningPreviewService::class)->preview($user, '2026-10-19', '2026-10-19');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
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
