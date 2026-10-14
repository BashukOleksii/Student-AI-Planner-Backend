<?php

namespace Tests\Feature;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;
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
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\PlanningWorkSelectionService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningConfirmationService;
use App\Services\Planning\StudyReplanningPersistenceService;
use App\Services\Planning\StudyReplanningPreviewService;
use App\Services\Planning\StudySessionReschedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StudyReplanningConfirmationTest extends TestCase
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
        $this->postJson('/api/planning/replanning/confirm', [])->assertUnauthorized();
        $this->assertSame('/api/planning/replanning/confirm', route('planning.replanning.confirm', [], false));
    }

    #[DataProvider('badBodies')]
    public function test_confirmation_body_is_strict(array $changes, array $errors): void
    {
        $this->fixture();
        $token = $this->preview()['preview_token'];
        $snapshot = $this->snapshot();
        $this->call('POST', '/api/planning/replanning/confirm', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['preview_token' => $token, ...$changes], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function badBodies(): iterable
    {
        foreach ([null, '', ' ', [], true, 1, 'invalid', ' token ', new \stdClass] as $value) {
            yield [['preview_token' => $value], ['preview_token']];
        }
        foreach (['accept_partial', 'accept_unplaced_cancellation'] as $field) {
            foreach ([null, 0, 1, 'true', 'false', '0', 1.0, [], new \stdClass, ''] as $value) {
                yield [[$field => $value], [$field]];
            }
        }
        $fields = ['user_id', 'owner_id', 'session_ids', 'sessions', 'replacements', 'status', 'task_id', 'subtask_id', 'starts_at'];
        yield [array_fill_keys($fields, null), $fields];
    }

    public function test_query_cannot_supply_token_or_acceptance_and_cannot_override_body(): void
    {
        [$user] = $this->fixture();
        $user->studyAvailabilityWindows()->delete();
        $token = $this->preview()['preview_token'];
        $snapshot = $this->snapshot();
        $this->postJson('/api/planning/replanning/confirm?preview_token='.urlencode($token))->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->postJson('/api/planning/replanning/confirm?accept_partial=true&accept_unplaced_cancellation=true', ['preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        $this->assertSame($snapshot, $this->snapshot());
        $this->postJson('/api/planning/replanning/confirm?preview_token=invalid&accept_partial=false',
            ['preview_token' => $token, 'accept_partial' => true, 'accept_unplaced_cancellation' => true])->assertOk();
    }

    public function test_full_batch_preserves_intervals_history_effort_and_reminders(): void
    {
        [$user, $task, $source] = $this->fixture();
        $ancestor = StudySession::factory()->for($task)->create(['status' => StudySessionStatus::Rescheduled,
            'starts_at' => '2026-10-18 10:00:00', 'ends_at' => '2026-10-18 11:00:00']);
        $source->update(['rescheduled_from_session_id' => $ancestor->id]);
        $scheduled = Reminder::factory()->for($user)->create(['study_session_id' => $source->id, 'message' => 'Keep metadata',
            'anchor' => ReminderAnchor::SessionStart, 'offset_minutes' => -15, 'trigger_at' => '2026-10-19 09:45:00']);
        $sent = Reminder::factory()->for($user)->create(['study_session_id' => $source->id, 'status' => ReminderStatus::Sent, 'sent_at' => '2026-10-19 07:00:00']);
        $cancelled = Reminder::factory()->for($user)->create(['study_session_id' => $source->id, 'status' => ReminderStatus::Cancelled]);
        $unrelated = Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $general = Reminder::factory()->for($user)->create();
        $completed = StudySession::factory()->for($task)->create(['status' => StudySessionStatus::Completed, 'completed_at' => '2026-10-19 08:00:00',
            'actual_minutes' => 30, 'starts_at' => '2026-10-19 07:00:00', 'ends_at' => '2026-10-19 08:00:00']);
        $historical = [$ancestor->fresh()->getAttributes(), $completed->fresh()->getAttributes(), $sent->fresh()->getAttributes(),
            $cancelled->fresh()->getAttributes(), $unrelated->fresh()->getAttributes(), $general->fresh()->getAttributes()];
        $preview = $this->preview();
        $sourceBefore = $source->fresh()->getAttributes();
        $reminderBefore = $scheduled->fresh()->getAttributes();
        $otherTables = $this->snapshot(['study_sessions', 'reminders']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->confirm($preview)->assertOk()->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $first = collect($queries)->first(fn ($query) => str_starts_with($query['query'], 'select '));
        $this->assertStringContainsString('from `users`', $first['query']);
        $this->assertStringContainsString('for update', $first['query']);
        $this->assertTrue($data['applied']);
        $this->assertSame([['id' => $source->id, 'status' => 'rescheduled']], $data['original_sessions']);
        $replacement = $data['replacement_sessions'][0];
        $this->assertSame($preview['session_results'][0]['replacement'], array_intersect_key($replacement, array_flip(['starts_at', 'ends_at', 'duration_seconds'])));
        $this->assertSame($task->id, $replacement['task_id']);
        $this->assertSame('planned', $replacement['status']);
        $this->assertNull($replacement['completed_at']);
        $this->assertNull($replacement['actual_minutes']);
        $this->assertArrayNotHasKey('user_id', $replacement);
        $this->assertArrayNotHasKey('rescheduled_from_session_id', $replacement);
        $this->assertArrayNotHasKey('preview_token', $data);
        $this->assertSame($source->id, StudySession::findOrFail($replacement['id'])->rescheduled_from_session_id);
        $this->assertSame($sourceBefore, array_replace($source->fresh()->getAttributes(), ['status' => 'planned']));
        $this->assertSame($reminderBefore, array_replace($scheduled->fresh()->getAttributes(), ['status' => 'scheduled']));
        $this->assertSame($historical, [$ancestor->fresh()->getAttributes(), $completed->fresh()->getAttributes(), $sent->fresh()->getAttributes(),
            $cancelled->fresh()->getAttributes(), $unrelated->fresh()->getAttributes(), $general->fresh()->getAttributes()]);
        $this->assertSame($otherTables, $this->snapshot(['study_sessions', 'reminders']));
        $effort = app(PlanningWorkSelectionService::class)->select($user, $task->id)['work_items'][0]['effort'];
        $this->assertSame(1800, $effort['completed_credit_seconds']);
        $this->assertSame(3600, $effort['future_reserved_seconds']);
        $this->assertSame(1800, $effort['unallocated_work_seconds']);
        $workload = app(PlanningContextService::class)->load($user, '2026-10-19', '2026-10-19')['workload_sessions'];
        $this->assertCount(2, $workload); // completed scheduled hour plus replacement hour
        $snapshot = $this->snapshot();
        $this->confirm($preview)->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
        // The immediate successor continues to support Stage D manual transfer.
        app(StudySessionReschedulingService::class)->reschedule($user, (string) $replacement['id'], '2026-10-19T13:00:00Z', '2026-10-19T14:00:00Z');
        $this->assertSame(1, StudySession::where('rescheduled_from_session_id', $replacement['id'])->count());
    }

    public function test_multiple_sources_and_work_units_preserve_valid_and_cannot_replan(): void
    {
        [$user, $task, $source] = $this->fixture();
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
        $source->update(['subtask_id' => $child->id]);
        $other = Task::factory()->for($user)->create(['estimated_minutes' => 120]);
        $second = StudySession::factory()->for($other)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        $validTask = Task::factory()->for($user)->create(['estimated_minutes' => 120]);
        $valid = StudySession::factory()->for($validTask)->create(['starts_at' => '2026-10-19 15:00:00', 'ends_at' => '2026-10-19 16:00:00']);
        $cannot = StudySession::factory()->for($validTask)->create(['starts_at' => '2026-10-19 07:00:00', 'ends_at' => '2026-10-19 08:00:00']);
        $preserved = [$valid->fresh()->getAttributes(), $cannot->fresh()->getAttributes()];
        $preview = $this->preview();
        $this->assertSame(2, $preview['summary']['affected_sessions']);
        $this->assertSame(1, $preview['summary']['valid_sessions']);
        $this->assertSame(1, $preview['summary']['cannot_replan_sessions']);
        $data = $this->confirm($preview)->assertOk()->json('data');
        $this->assertSame(2, $data['summary']['created_replacements']);
        foreach ([$source, $second] as $original) {
            $successor = StudySession::where('rescheduled_from_session_id', $original->id)->sole();
            $this->assertSame($original->task_id, $successor->task_id);
            $this->assertSame($original->subtask_id, $successor->subtask_id);
        }
        $this->assertSame($preserved, [$valid->fresh()->getAttributes(), $cannot->fresh()->getAttributes()]);
    }

    #[DataProvider('acceptances')]
    public function test_partial_unplaced_and_mixed_acceptance(string $kind, bool $partial, bool $cancel, ?string $error): void
    {
        [$user, $task, $source, $lesson] = $this->fixture();
        $task->update(['estimated_minutes' => 240]);
        if ($kind === 'unplaced') {
            $user->studyAvailabilityWindows()->delete();
        } else {
            $user->studyAvailabilityWindows()->update(['ends_at' => $kind === 'mixed' ? '10:30:00' : '09:30:00']);
            PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 30, 'max_session_minutes' => 60]);
        }
        if ($kind === 'mixed') {
            $lesson->delete();
            foreach (['12:00:00', '14:00:00'] as $start) {
                StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 '.$start,
                    'ends_at' => CarbonImmutable::parse('2026-10-19 '.$start, 'UTC')->addHour()->format('Y-m-d H:i:s')]);
            }
        }
        $reminder = Reminder::factory()->for($user)->create(['study_session_id' => $source->id]);
        $preview = $this->preview();
        $snapshot = $this->snapshot();
        $response = $this->confirm($preview, $partial, $cancel);
        if ($error !== null) {
            $response->assertUnprocessable()->assertJsonValidationErrors($error);
            $this->assertSame($snapshot, $this->snapshot());

            return;
        }
        $data = $response->assertOk()->json('data');
        $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
        $this->assertSame($kind === 'unplaced' ? 0 : ($kind === 'mixed' ? 2 : 1), $data['summary']['created_replacements']);
        $this->assertSame($kind === 'partial' ? 0 : 1, $data['summary']['cancelled_unplaced_sessions']);
        $this->assertSame($kind === 'unplaced' ? 3600 : ($kind === 'mixed' ? 5400 : 1800), $data['summary']['untransferred_seconds']);
        foreach ($preview['session_results'] as $row) {
            $original = StudySession::findOrFail($row['study_session_id']);
            $this->assertSame($row['replacement'] === null ? StudySessionStatus::Cancelled : StudySessionStatus::Rescheduled, $original->status);
            $this->assertSame($row['replacement'] === null ? 0 : 1, StudySession::where('rescheduled_from_session_id', $original->id)->count());
        }
        $effort = app(PlanningWorkSelectionService::class)->select($user, $task->id)['work_items'][0]['effort'];
        $this->assertSame(0, $effort['completed_credit_seconds']);
        $this->assertSame($preview['summary']['replacement_seconds'], $effort['future_reserved_seconds']);
        if ($kind === 'unplaced') {
            StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => 1, 'starts_at' => '14:00:00', 'ends_at' => '18:00:00']);
            $plan = app(StudyPlanPersistenceService::class)->preview($user, '2026-10-19', '2026-10-19', $task->id);
            $this->assertSame(14400, $plan['plan']['summary']['allocated_seconds']);
            app(StudyPlanPersistenceService::class)->confirm($user, $plan['preview_token']);
            $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
        }
    }

    public static function acceptances(): array
    {
        return [['partial', false, false, 'accept_partial'], ['partial', true, false, null],
            ['unplaced', false, false, 'accept_partial'], ['unplaced', false, true, 'accept_partial'],
            ['unplaced', true, false, 'accept_unplaced_cancellation'], ['unplaced', true, true, null],
            ['mixed', false, false, 'accept_partial'], ['mixed', true, false, 'accept_unplaced_cancellation'], ['mixed', true, true, null]];
    }

    #[DataProvider('noActions')]
    public function test_no_action_is_never_a_success(string $kind): void
    {
        [$user, $task, $source, $lesson] = $this->fixture();
        if ($kind === 'valid') {
            $lesson->delete();
        }
        if ($kind === 'empty') {
            $source->delete();
        }
        if ($kind === 'cannot') {
            $task->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 07:00:00']);
        }
        $preview = $this->preview();
        $snapshot = $this->snapshot();
        $this->confirm($preview, true, true)->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function noActions(): array
    {
        return [['valid'], ['empty'], ['cannot']];
    }

    #[DataProvider('staleChanges')]
    public function test_current_material_changes_conflict_without_partial_writes(string $change): void
    {
        [$user, $task, $source, $lesson] = $this->fixture();
        if (str_starts_with($change, 'child')) {
            $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
            $source->update(['subtask_id' => $child->id]);
        }
        $preview = $this->preview();
        switch ($change) {
            case 'completed': $source->update(['status' => 'completed', 'completed_at' => '2026-10-19 08:00:00', 'actual_minutes' => 30]);
                break;
            case 'cancelled': case 'rescheduled': case 'missed': $source->update(['status' => $change]);
                break;
            case 'interval': $source->update(['ends_at' => '2026-10-19 11:00:01']);
                break;
            case 'task completed': $task->update(['status' => 'completed', 'completed_at' => '2026-10-19 08:00:00']);
                break;
            case 'task deleted': $task->delete();
                break;
            case 'child completed': $child->update(['status' => 'completed', 'completed_at' => '2026-10-19 08:00:00']);
                break;
            case 'child deleted': $child->delete();
                break;
            case 'child estimate': $child->update(['estimated_minutes' => 90]);
                break;
            case 'child deadline': $child->update(['deadline_at' => '2026-10-19 09:30:00']);
                break;
            case 'decomposed': Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
                break;
            case 'estimate': $task->update(['estimated_minutes' => 90]);
                break;
            case 'deadline': $task->update(['deadline_at' => '2026-10-19 09:30:00']);
                break;
            case 'lesson': $lesson->delete();
                break;
            case 'availability': $user->studyAvailabilityWindows()->update(['starts_at' => '09:30:00']);
                break;
            case 'preferences': PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30]);
                break;
            case 'timezone': $user->update(['timezone' => 'Europe/Kyiv']);
                break;
            case 'reservation': StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
                break;
            case 'completed effort': StudySession::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-18 08:00:00', 'actual_minutes' => 45]);
                break;
            case 'manual reschedule': app(StudySessionReschedulingService::class)->reschedule($user, (string) $source->id, '2026-10-19T13:00:00Z', '2026-10-19T14:00:00Z');
                break;
            case 'daily': PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 30]);
                break;
            case 'weekly': PlanningPreference::factory()->for($user)->create(['max_weekly_study_minutes' => 30]);
                break;
            case 'break': PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 15]);
                break;
        }
        $snapshot = $this->snapshot();
        $this->confirm($preview, true, true)->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function staleChanges(): array
    {
        return array_map(fn ($change) => [$change], ['completed', 'cancelled', 'rescheduled', 'missed', 'interval', 'task completed', 'task deleted',
            'child completed', 'child deleted', 'child estimate', 'child deadline', 'decomposed', 'estimate', 'deadline', 'lesson',
            'availability', 'preferences', 'timezone', 'reservation', 'completed effort', 'manual reschedule', 'daily', 'weekly', 'break']);
    }

    #[DataProvider('invalidTokens')]
    public function test_tokens_are_encrypted_expiring_and_owner_purpose_bound(string $kind): void
    {
        [$user] = $this->fixture();
        $preview = $this->preview();
        if ($kind === 'wrong owner') {
            $this->actingAs(User::factory()->create());
        }
        if ($kind === 'expired') {
            $this->travelTo(CarbonImmutable::parse($preview['expires_at']));
        }
        if ($kind === 'tampered') {
            $preview['preview_token'][30] = $preview['preview_token'][30] === 'a' ? 'b' : 'a';
        }
        if ($kind === 'stage c') {
            $preview = app(StudyPlanPersistenceService::class)->preview($user, '2026-10-19', '2026-10-19');
        }
        $snapshot = $this->snapshot();
        $this->confirm($preview)->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function invalidTokens(): array
    {
        return [['wrong owner'], ['expired'], ['tampered'], ['stage c']];
    }

    public function test_e2_token_cannot_confirm_stage_c(): void
    {
        $this->fixture();
        $snapshot = $this->snapshot();
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $this->preview()['preview_token']])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertSame($snapshot, $this->snapshot());
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_source_or_reminder_rolls_back_and_hides_private_details(string $kind): void
    {
        [$user, $task, $source] = $this->fixture();
        $preview = $this->preview();
        $foreign = Task::factory()->create();
        switch ($kind) {
            case 'foreign task': $source->update(['task_id' => $foreign->id]);
                break;
            case 'foreign child': $source->update(['subtask_id' => Subtask::factory()->for($foreign)->create()->id]);
                break;
            case 'chain': $ancestor = StudySession::factory()->for($foreign)->create(['status' => 'rescheduled']);
                $source->update(['rescheduled_from_session_id' => $ancestor->id]);
                break;
            case 'self': $source->update(['rescheduled_from_session_id' => $source->id]);
                break;
            case 'successor': StudySession::factory()->for($task)->create(['rescheduled_from_session_id' => $source->id]);
                break;
            case 'reminder owner': Reminder::factory()->create(['study_session_id' => $source->id]);
                break;
            case 'reminder targets': Reminder::factory()->for($user)->create(['study_session_id' => $source->id, 'task_id' => $task->id]);
                break;
            case 'reminder anchor': Reminder::factory()->for($user)->create(['study_session_id' => $source->id, 'anchor' => 'task_deadline', 'offset_minutes' => 0]);
                break;
        }
        $snapshot = $this->snapshot();
        $response = $this->confirm($preview)->assertStatus(500);
        $this->assertSame('invalid_source', $response->json('classification'));
        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function corruptions(): array
    {
        return array_map(fn ($case) => [$case], ['foreign task', 'foreign child', 'chain', 'self', 'successor', 'reminder owner', 'reminder targets', 'reminder anchor']);
    }

    #[DataProvider('failurePoints')]
    public function test_failures_and_elapsed_time_restore_exact_database_snapshot(string $point, int $status): void
    {
        [$user, $task, $source] = $this->fixture();
        $task->update(['estimated_minutes' => 240]);
        $second = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        foreach ([$source, $second] as $row) {
            Reminder::factory()->for($user)->create(['study_session_id' => $row->id]);
        }
        $preview = $this->preview();
        $snapshot = $this->snapshot();
        $fail = fn () => throw new RuntimeException('Private injected SQL/observer detail');
        if ($point === 'predecessor') {
            StudySession::updated($fail);
        }
        if ($point === 'successor') {
            StudySession::created($fail);
        }
        if ($point === 'second successor') {
            $inserts = 0;
            StudySession::created(function () use (&$inserts, $fail) {
                if (++$inserts === 2) {
                    $fail();
                }
            });
        }
        if ($point === 'reminder') {
            Reminder::updated($fail);
        }
        if ($point === 'save cancelled') {
            StudySession::creating(fn () => false);
        }
        if ($point === 'replacement starts') {
            StudySession::created(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19T09:00:00.000001Z')));
        }
        if ($point === 'source starts') {
            StudySession::updated(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19T10:00:00.000001Z')));
        }
        if ($point === 'token expires') {
            Reminder::updated(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse($preview['expires_at'])));
        }
        try {
            $response = $this->confirm($preview)->assertStatus($status);
            $this->assertStringNotContainsString('Private injected', $response->getContent());
        } finally {
            StudySession::flushEventListeners();
            Reminder::flushEventListeners();
        }
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function failurePoints(): array
    {
        return [['predecessor', 500], ['successor', 500], ['second successor', 500], ['reminder', 500], ['save cancelled', 500],
            ['replacement starts', 409], ['source starts', 409], ['token expires', 422]];
    }

    public function test_ambient_transaction_is_rejected_without_mutation(): void
    {
        [$user] = $this->fixture();
        $preview = $this->preview();
        $snapshot = $this->snapshot();
        DB::beginTransaction();
        try {
            app(StudyReplanningPersistenceService::class)->confirm($user, $preview['preview_token']);
            $this->fail('Ambient transaction was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('outermost', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($snapshot, $this->snapshot());
    }

    #[DataProvider('calendars')]
    public function test_confirmation_respects_dst_and_local_iso_weeks(string $date, string $start, string $end, string $now): void
    {
        [$user, $task, $source, $lesson] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse($now));
        $user->update(['timezone' => 'Europe/Kyiv']);
        $task->update(['estimated_minutes' => 180]);
        $source->update(['starts_at' => CarbonImmutable::parse($start)->format('Y-m-d H:i:s'), 'ends_at' => CarbonImmutable::parse($end)->format('Y-m-d H:i:s')]);
        $lesson->update(['starts_at' => $source->getRawOriginal('starts_at'), 'ends_at' => $source->getRawOriginal('ends_at')]);
        $user->studyAvailabilityWindows()->update(['starts_at' => '00:00:00', 'ends_at' => '24:00:00']);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 180, 'max_weekly_study_minutes' => 180]);
        $preview = $this->postJson('/api/planning/replanning/preview', ['start_date' => $date, 'end_date' => $date])->assertOk()->json('data');
        $data = $this->confirm($preview)->assertOk()->json('data');
        $this->assertSame($preview['session_results'][0]['original']['duration_seconds'], $data['replacement_sessions'][0]['duration_seconds']);
        $this->assertSame('Europe/Kyiv', $preview['timezone']);
    }

    public static function calendars(): array
    {
        return [['2027-03-28', '2027-03-28T00:30:00Z', '2027-03-28T02:30:00Z', '2027-03-27T08:00:00Z'],
            ['2026-10-25', '2026-10-25T00:30:00Z', '2026-10-25T02:30:00Z', '2026-10-24T08:00:00Z'],
            ['2027-01-04', '2027-01-03T21:30:00Z', '2027-01-03T22:30:00Z', '2027-01-03T08:00:00Z']];
    }

    #[DataProvider('independentFailures')]
    public function test_matching_preview_does_not_bypass_independent_batch_gate(string $case): void
    {
        [$user, $task, $source] = $this->fixture();
        if ($case === 'effort') {
            $task->update(['estimated_minutes' => 30]);
        }
        if ($case === 'deadline') {
            $task->update(['deadline_at' => '2026-10-19 09:30:00']);
        }
        if ($case === 'break') {
            PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 15]);
        }
        if (in_array($case, ['daily', 'weekly'], true)) {
            PlanningPreference::factory()->for($user)->create([$case === 'daily' ? 'max_daily_study_minutes' : 'max_weekly_study_minutes' => 30]);
        }
        if ($case === 'collective effort' || $case === 'collective overlap') {
            StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
            $task->update(['estimated_minutes' => $case === 'collective effort' ? 60 : 120]);
        }
        $review = app(StudyReplanningPreviewService::class)->preview($user, '2026-10-19', '2026-10-19');
        foreach ($review['session_results'] as $index => &$row) {
            $start = strtotime($case === 'availability' ? '2026-10-19T18:00:00Z' : ($index === 1 && $case === 'collective effort' ? '2026-10-19T11:00:00Z' : '2026-10-19T09:00:00Z'));
            $row['replacement'] = ['task_id' => $task->id, 'subtask_id' => null, 'start' => $start, 'end' => $start + 3600, 'duration_seconds' => 3600];
        }
        unset($row);
        $parameters = ['start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'task_id' => null, 'subtask_id' => null];
        $token = app(StudyReplanningConfirmationService::class)->issue($user->id, $parameters, $review, CarbonImmutable::now('UTC')->getTimestamp());
        $this->mock(StudyReplanningPreviewService::class, fn ($mock) => $mock->shouldReceive('preview')->once()->andReturn($review));
        $snapshot = $this->snapshot();
        $this->confirm(['preview_token' => $token], true, true)->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame(StudySessionStatus::Planned, $source->fresh()->status);
    }

    public static function independentFailures(): array
    {
        return array_map(fn ($case) => [$case], ['availability', 'deadline', 'break', 'daily', 'weekly', 'effort', 'collective effort', 'collective overlap']);
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => 120]);
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $lesson = Lesson::factory()->for($user)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        foreach (range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);
        }
        $this->actingAs($user);

        return [$user, $task, $source, $lesson];
    }

    private function preview(): array
    {
        return $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'])->assertOk()->json('data');
    }

    private function confirm(array $preview, bool $partial = false, bool $cancel = false)
    {
        return $this->postJson('/api/planning/replanning/confirm', ['preview_token' => $preview['preview_token'], 'accept_partial' => $partial, 'accept_unplaced_cancellation' => $cancel]);
    }

    private function snapshot(array $except = []): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (! in_array($table, $except, true)) {
                $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
                usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                $result[$table] = $rows;
            }
        }
        ksort($result);

        return $result;
    }
}
