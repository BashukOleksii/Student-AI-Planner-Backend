<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\StudyPlanningService;
use App\Services\Planning\StudyPlanPersistenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StudyPlanApiTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        // These tests commit real outermost transactions, so remove committed fixtures too.
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_all_routes_require_authentication_before_validation_and_detail_lookup(): void
    {
        $this->postJson('/api/planning/study-plans/preview', [])->assertUnauthorized();
        $this->postJson('/api/planning/study-plans/confirm', [])->assertUnauthorized();
        $this->getJson('/api/study-sessions')->assertUnauthorized();
        $this->getJson('/api/study-sessions/999')->assertUnauthorized();
        $this->assertSame('/api/planning/study-plans/preview', route('planning.study-plans.preview', [], false));
        $this->assertSame('/api/planning/study-plans/confirm', route('planning.study-plans.confirm', [], false));
        $this->assertSame('/api/study-sessions', route('study-sessions.index', [], false));
    }

    public function test_preview_is_actual_stage_b_with_public_utc_resource_encrypted_token_and_no_writes(): void
    {
        [$user, $task] = $this->fixture();
        Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $expected = app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19');
        $snapshot = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->preview();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame($expected['summary'], $data['summary']);
        $this->assertSame($expected['work_results'], $data['work_results']);
        $this->assertSame(['task_id' => $task->id, 'subtask_id' => null, 'starts_at' => '2026-10-19T09:00:00Z',
            'ends_at' => '2026-10-19T10:00:00Z', 'duration_seconds' => 3600], $data['sessions'][0]);
        $this->assertSame('2026-10-19T08:15:00Z', $data['expires_at']);
        $this->assertIsString($data['preview_token']);
        $this->assertStringNotContainsString('2026-10-19', base64_decode($data['preview_token']));
        $this->assertArrayNotHasKey('user_id', $data);
    }

    public function test_full_plan_is_created_with_exact_fields_and_no_other_application_mutations(): void
    {
        [$user, $task] = $this->fixture();
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 60]);
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30, 'preferred_break_minutes' => 15]);
        Reminder::factory()->for($user)->create(['subtask_id' => $child->id]);
        $data = $this->preview(['task_id' => $task->id, 'subtask_id' => $child->id]);
        $snapshot = $this->snapshot(['study_sessions']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:01:00.250000Z'));
        $saved = $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])
            ->assertCreated()->assertJsonPath('data.summary.fully_allocated', true)->json('data');
        $this->assertArrayNotHasKey('preview_token', $saved);
        $this->assertSame($data['work_results'], $saved['work_results']);
        $this->assertSame($snapshot, $this->snapshot(['study_sessions']));
        $this->assertCount(2, $saved['sessions']);
        foreach (StudySession::orderBy('id')->get() as $index => $session) {
            $this->assertSame($user->id, $session->user_id);
            $this->assertSame($task->id, $session->task_id);
            $this->assertSame($child->id, $session->subtask_id);
            $this->assertSame(StudySessionStatus::Planned, $session->status);
            $this->assertNull($session->completed_at);
            $this->assertNull($session->actual_minutes);
            $this->assertNull($session->rescheduled_from_session_id);
            $this->assertSame($saved['sessions'][$index]['id'], $session->id);
            $this->assertSame($data['sessions'][$index]['starts_at'], $saved['sessions'][$index]['starts_at']);
            $this->getJson('/api/study-sessions/'.$session->id)->assertOk()->assertExactJson(['data' => $saved['sessions'][$index]]);
        }
        $context = app(PlanningContextService::class)->load($user, '2026-10-19', '2026-10-19');
        $free = app(FreeTimeService::class)->calculate($user, '2026-10-19', '2026-10-19');
        $this->assertSame($free, $context['free_intervals']);
        $this->assertSame([
            ['2026-10-19T09:30:00Z', '2026-10-19T09:45:00Z'],
            ['2026-10-19T10:15:00Z', '2026-10-19T12:00:00Z'],
        ], array_map(fn ($interval) => [gmdate('Y-m-d\TH:i:s\Z', $interval['start']), gmdate('Y-m-d\TH:i:s\Z', $interval['end'])], $free));
        $this->assertSame([], app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19')['sessions']);
        $this->assertSame(0, app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19')['summary']['requested_seconds']);
    }

    public function test_partial_plan_requires_explicit_true_and_persists_only_the_reviewed_allocation(): void
    {
        [$user] = $this->fixture(240);
        $data = $this->preview();
        $this->assertFalse($data['summary']['fully_allocated']);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])
            ->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token'], 'accept_partial' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        $this->assertDatabaseCount('study_sessions', 0);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token'], 'accept_partial' => true])
            ->assertCreated()->assertJsonPath('data.summary.unscheduled_seconds', 3600)
            ->assertJsonPath('data.summary.allocated_seconds', 10800)->assertJsonPath('data.summary.fully_allocated', false);
        $this->assertDatabaseCount('study_sessions', 1);
        $this->assertSame(3600, app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19')['summary']['requested_seconds']);
    }

    #[DataProvider('emptyCases')]
    public function test_empty_plan_is_a_valid_preview_but_cannot_be_confirmed(string $case): void
    {
        [$user, $task] = $this->fixture();
        match ($case) {
            'no availability' => $user->studyAvailabilityWindows()->delete(),
            'no work' => $task->delete(),
            'missing estimate' => $task->update(['estimated_minutes' => null]),
        };
        $data = $this->preview();
        $this->assertSame([], $data['sessions']);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token'], 'accept_partial' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public static function emptyCases(): array
    {
        return [['no availability'], ['no work'], ['missing estimate']];
    }

    #[DataProvider('invalidPreviewInput')]
    public function test_preview_input_is_exact_body_only_and_rejects_server_fields(array $values, string $field): void
    {
        $this->fixture();
        $this->call('POST', '/api/planning/study-plans/preview', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(array_replace($this->parameters(), $values), JSON_PRESERVE_ZERO_FRACTION))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public static function invalidPreviewInput(): array
    {
        $cases = [];
        foreach (['start_date', 'end_date'] as $field) {
            foreach ([' 2026-10-19', '2026-10-19 ', '2026-1-19', '2026-02-29', '', null, [], '0000-01-01'] as $value) {
                $cases[] = [[$field => $value], $field];
            }
        }
        foreach (['task_id', 'subtask_id'] as $field) {
            foreach (['1', 1.0, true, false, 0, -1, '', []] as $value) {
                $cases[] = [[$field => $value], $field];
            }
        }
        foreach (['user_id', 'timezone', 'sessions', 'status', 'actual_minutes', 'preview_token'] as $field) {
            $cases[] = [[$field => null], $field];
        }

        return [...$cases, [['end_date' => '2026-10-18'], 'end_date'], [['end_date' => '2026-11-19'], 'end_date'], [['subtask_id' => 1], 'task_id']];
    }

    public function test_post_query_parameters_never_override_or_supply_body_input(): void
    {
        $this->fixture();
        $this->postJson('/api/planning/study-plans/preview?start_date=2026-10-19&end_date=2026-10-19', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $data = $this->postJson('/api/planning/study-plans/preview?task_id=999&start_date=bad', $this->parameters())->assertOk()->json('data');
        $this->postJson('/api/planning/study-plans/confirm?preview_token='.$data['preview_token'], [])->assertUnprocessable();
        $this->postJson('/api/planning/study-plans/confirm?accept_partial=bad', ['preview_token' => $data['preview_token']])->assertCreated();
    }

    #[DataProvider('badConfirmInput')]
    public function test_confirmation_rejects_invalid_structure_and_client_session_fields(array $values, string $field): void
    {
        $this->fixture();
        $this->postJson('/api/planning/study-plans/confirm', $values)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public static function badConfirmInput(): array
    {
        $cases = [[[], 'preview_token']];
        foreach ([null, '', [], true, 123, 'bad token'] as $token) {
            $cases[] = [['preview_token' => $token], 'preview_token'];
        }
        foreach ([null, '', 'true', 'false', '1', 1, 0, []] as $value) {
            $cases[] = [['preview_token' => 'dGVzdA==', 'accept_partial' => $value], 'accept_partial'];
        }
        foreach (['sessions', 'user_id', 'task_id', 'status', 'completed_at', 'actual_minutes', 'rescheduled_from_session_id'] as $field) {
            $cases[] = [['preview_token' => 'dGVzdA==', $field => null], $field];
        }

        return $cases;
    }

    #[DataProvider('foreignTargets')]
    public function test_explicit_foreign_missing_and_mismatched_work_has_generic_not_found(string $case): void
    {
        [$user, $task] = $this->fixture();
        $foreign = User::factory()->create();
        $foreignTask = Task::factory()->for($foreign)->create(['estimated_minutes' => 60]);
        $parameters = $this->parameters();
        $parameters['task_id'] = match ($case) {
            'foreign task' => $foreignTask->id, 'missing task' => PHP_INT_MAX, default => $task->id,
        };
        if ($case === 'foreign child') {
            $parameters['subtask_id'] = Subtask::factory()->for($foreignTask)->create()->id;
        } elseif ($case === 'wrong parent') {
            $parameters['subtask_id'] = Subtask::factory()->for(Task::factory()->for($user)->create())->create()->id;
        } elseif ($case === 'missing child') {
            $parameters['subtask_id'] = PHP_INT_MAX;
        }
        $this->postJson('/api/planning/study-plans/preview', $parameters)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public static function foreignTargets(): array
    {
        return [['foreign task'], ['missing task'], ['foreign child'], ['wrong parent'], ['missing child']];
    }

    #[DataProvider('invalidTokens')]
    public function test_tampered_expired_and_wrong_owner_tokens_have_generic_validation_without_writes(string $case): void
    {
        $this->fixture();
        $data = $this->preview();
        $token = $data['preview_token'];
        if ($case === 'expired') {
            $this->travelTo(CarbonImmutable::parse($data['expires_at']));
        } elseif ($case === 'owner') {
            Auth::forgetGuards();
            $this->actingAs(User::factory()->create(), 'web');
        } else {
            $wrapper = json_decode(base64_decode($token), true);
            $wrapper['mac'] = str_repeat('0', 64);
            $token = base64_encode(json_encode($wrapper));
        }
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public static function invalidTokens(): array
    {
        return [['expired'], ['owner'], ['tampered']];
    }

    #[DataProvider('staleChanges')]
    public function test_material_changes_reject_the_review_without_automatic_replacement(string $change): void
    {
        [$user, $task] = $this->fixture();
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 60]);
        $data = $this->preview(['task_id' => $task->id, 'subtask_id' => $child->id]);
        match ($change) {
            'estimate' => $child->update(['estimated_minutes' => 90]),
            'parent completed' => $task->update(['status' => 'completed', 'completed_at' => '2026-10-19 08:00:00']),
            'child completed' => $child->update(['status' => 'completed', 'completed_at' => '2026-10-19 08:00:00']),
            'deadline' => $task->update(['deadline_at' => '2026-10-19 09:30:00']),
            'child deadline' => $child->update(['deadline_at' => '2026-10-19 09:30:00']),
            'availability' => $user->studyAvailabilityWindows()->update(['starts_at' => '10:00:00']),
            'preferences' => PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30]),
            'lesson' => Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']),
            'session' => StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']),
            'timezone' => $user->update(['timezone' => 'Europe/Kyiv']),
            'deleted task' => $task->delete(),
            'deleted child' => $child->delete(),
        };
        $before = $this->snapshot();
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token'], 'accept_partial' => true])
            ->assertConflict()->assertExactJson(['message' => 'The study plan preview is no longer current. Obtain a fresh preview and review it before confirming.']);
        $this->assertSame($before, $this->snapshot());
    }

    public static function staleChanges(): array
    {
        return array_map(fn ($case) => [$case], ['estimate', 'parent completed', 'child completed', 'deadline', 'child deadline',
            'availability', 'preferences', 'lesson', 'session', 'timezone', 'deleted task', 'deleted child']);
    }

    public function test_preview_becoming_historical_rejects_even_before_token_expiration(): void
    {
        [$user] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:59:00Z'));
        $data = $this->preview();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T09:00:00Z'));
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])->assertConflict();
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_repeated_confirmation_is_conflict_and_never_creates_duplicate_reservations(): void
    {
        $this->fixture();
        $data = $this->preview();
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])->assertCreated();
        $snapshot = $this->snapshot();
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_changed_task_level_estimate_is_material_while_unrelated_changes_need_no_new_review(): void
    {
        [$user, $task] = $this->fixture();
        $data = $this->preview();
        $task->update(['estimated_minutes' => 90]);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])->assertConflict();
        $task->update(['estimated_minutes' => 60, 'title' => 'New presentation title']);
        // A compatible configured minimum changes no reviewed proposal or outcome.
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 30]);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])->assertCreated();
        $this->assertDatabaseCount('study_sessions', 1);
    }

    #[DataProvider('invalidBatchCases')]
    public function test_independent_batch_gate_rejects_invalid_regenerated_proposals_before_any_insert(string $violation): void
    {
        [$user, $task] = $this->fixture();
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 15, 'max_session_minutes' => 30,
            'preferred_break_minutes' => 15, 'max_daily_study_minutes' => 60, 'max_weekly_study_minutes' => 60]);
        $plan = app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19');
        if ($violation === 'overlap') {
            $plan['sessions'][1]['start'] = $plan['sessions'][0]['start'] + 900;
            $plan['sessions'][1]['end'] = $plan['sessions'][1]['start'] + 1800;
        } elseif ($violation === 'break') {
            $plan['sessions'][1]['start'] = $plan['sessions'][0]['end'] + 899;
            $plan['sessions'][1]['end'] = $plan['sessions'][1]['start'] + 1800;
        } elseif ($violation === 'duration') {
            $plan['sessions'][0]['end'] = $plan['sessions'][0]['start'] + 899;
            $plan['sessions'][0]['duration_seconds'] = 899;
        } elseif ($violation === 'completed') {
            $task->update(['status' => 'completed', 'completed_at' => '2026-10-19 08:00:00']);
        } elseif ($violation === 'effort') {
            $task->update(['estimated_minutes' => 30]);
        } elseif ($violation === 'workload') {
            $history = Task::factory()->for($user)->create();
            StudySession::factory()->for($history)->create(['status' => 'completed', 'starts_at' => '2026-10-19 07:00:00',
                'ends_at' => '2026-10-19 07:30:00', 'completed_at' => '2026-10-19 07:30:00', 'actual_minutes' => 1]);
        } elseif ($violation === 'deadline') {
            $task->update(['deadline_at' => '2026-10-19 09:45:00']);
        } elseif ($violation === 'foreign attribution') {
            $plan['sessions'][0]['task_id'] = Task::factory()->create()->id;
        }
        // A faulty adapter/planner returning a matching review must still fail the independent
        // current Stage A/context and complete-batch PF2 checks. Production uses the real planner.
        $mock = $this->createMock(StudyPlanningService::class);
        $mock->method('plan')->willReturn($plan);
        $this->app->instance(StudyPlanningService::class, $mock);
        $review = app(StudyPlanPersistenceService::class)->preview($user, '2026-10-19', '2026-10-19');
        $before = $this->snapshot();
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $review['preview_token']])->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidBatchCases(): array
    {
        return array_map(fn ($case) => [$case], ['overlap', 'break', 'duration', 'completed', 'effort', 'workload', 'deadline', 'foreign attribution']);
    }

    #[DataProvider('insertFailures')]
    public function test_later_insert_failure_or_time_change_rolls_back_the_entire_batch(string $failure): void
    {
        [$user] = $this->fixture();
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30]);
        if ($failure === 'historical') {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T08:59:00Z'));
        }
        $data = $this->preview();
        $before = $this->snapshot();
        $count = 0;
        StudySession::creating(function ($session) use (&$count, $failure) {
            if (++$count === 2) {
                if ($failure === 'exception') {
                    throw new RuntimeException('Private model failure');
                }
                if ($failure === 'cancelled save') {
                    return false;
                }
                if ($failure === 'historical') {
                    $this->travelTo(CarbonImmutable::parse('2026-10-19T09:00:01Z'));
                } else {
                    $this->travelTo(CarbonImmutable::parse('2026-10-19T08:15:00Z'));
                }
            }
        });
        try {
            $response = $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']]);
            match ($failure) {
                'historical' => $response->assertConflict(),
                'expired' => $response->assertUnprocessable(),
                default => $response->assertStatus(500)->assertExactJson(['message' => 'The study plan could not be processed.']),
            };
            $this->assertSame($before, $this->snapshot());
            $this->assertDatabaseCount('study_sessions', 0);
        } finally {
            StudySession::flushEventListeners();
        }
    }

    public static function insertFailures(): array
    {
        return [['exception'], ['cancelled save'], ['historical'], ['expired']];
    }

    public function test_complete_batch_is_revalidated_with_every_other_candidate_once(): void
    {
        [$user] = $this->fixture();
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30, 'preferred_break_minutes' => 15,
            'max_daily_study_minutes' => 60, 'max_weekly_study_minutes' => 60]);
        $data = $this->preview();
        $result = $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])->assertCreated()->json('data');
        $this->assertSame(['2026-10-19T09:00:00Z', '2026-10-19T09:45:00Z'], array_column($result['sessions'], 'starts_at'));
        $this->assertSame(3600, array_sum(array_column($result['sessions'], 'duration_seconds')));
        $this->assertDatabaseCount('study_sessions', 2);
        $next = $this->preview();
        $this->assertSame([], $next['sessions']);
    }

    public function test_owned_source_corruption_is_generic_server_failure_even_with_debug_enabled(): void
    {
        [$user, $task] = $this->fixture();
        $data = $this->preview();
        $foreignTask = Task::factory()->create();
        StudySession::factory()->create(['user_id' => $user->id, 'task_id' => $foreignTask->id,
            'starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        config(['app.debug' => true]);
        $this->postJson('/api/planning/study-plans/preview', $this->parameters())->assertStatus(500)
            ->assertExactJson(['message' => 'The study plan could not be processed.']);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])->assertStatus(500)
            ->assertExactJson(['message' => 'The study plan could not be processed.']);
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_confirmation_refuses_an_ambient_transaction_instead_of_reusing_a_stale_read_view(): void
    {
        [$user] = $this->fixture();
        $preview = app(StudyPlanPersistenceService::class)->preview($user, '2026-10-19', '2026-10-19');
        DB::beginTransaction();
        try {
            DB::table('study_sessions')->get();
            try {
                app(StudyPlanPersistenceService::class)->confirm($user, $preview['preview_token']);
                $this->fail('An ambient transaction must fail closed.');
            } catch (LogicException $exception) {
                $this->assertSame('Study plan confirmation requires an outermost transaction.', $exception->getMessage());
            }
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('study_sessions', 0);
    }

    private function fixture(int $estimate = 60): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => $estimate]);
        $this->actingAs($user, 'web');

        return [$user, $task];
    }

    private function parameters(): array
    {
        return ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'];
    }

    private function preview(array $values = []): array
    {
        return $this->postJson('/api/planning/study-plans/preview', array_replace($this->parameters(), $values))->assertOk()->json('data');
    }

    private function snapshot(array $except = []): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (! in_array($table, $except, true)) {
                $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
                usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                $snapshot[$table] = $rows;
            }
        }
        ksort($snapshot);

        return $snapshot;
    }
}
