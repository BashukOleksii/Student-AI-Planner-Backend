<?php

namespace Tests\Feature;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;
use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Lesson;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
use App\Services\Reminders\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\ReminderRulesTest;

class ReminderApiTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_all_endpoints_require_authentication(): void
    {
        $this->getJson('/api/reminders')->assertUnauthorized();
        $this->postJson('/api/reminders', $this->body())->assertUnauthorized();
        $this->getJson('/api/reminders/999')->assertUnauthorized();
        $this->patchJson('/api/reminders/999', ['message' => 'New'])->assertUnauthorized();
        $this->postJson('/api/reminders/999/cancel')->assertUnauthorized();
    }

    #[DataProvider('targets')]
    public function test_create_each_target_or_general_preserves_all_other_tables(?string $field): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $target = match ($field) {
            'task_id' => $task->id, 'subtask_id' => $child->id, 'study_session_id' => $session->id, default => null
        };
        $before = $this->snapshot(['reminders']);
        $body = $field === null ? $this->body() : ['trigger_at' => $this->body()['trigger_at'], $field => $target, 'message' => null];
        $data = $this->postJson('/api/reminders', $body)->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.sent_at', null)
            ->assertJsonPath('data.anchor', null)->assertJsonPath('data.offset_minutes', null)->json('data');
        $this->assertSame($before, $this->snapshot(['reminders']));
        $this->assertSame($owner->id, Reminder::findOrFail($data['id'])->user_id);
        $this->assertSame($body['trigger_at'], $data['trigger_at']);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->getJson('/api/reminders/'.$data['id'])->assertOk()->assertJsonPath('data.id', $data['id']);
    }

    public static function targets(): array
    {
        return [[null], ['task_id'], ['subtask_id'], ['study_session_id']];
    }

    #[DataProvider('invalidInputs')]
    public function test_strict_body_validation(array $change, string $field): void
    {
        $this->actingAs(User::factory()->create());
        if (($change['message'] ?? null) === "\xff") {
            // Invalid UTF-8 is exercised through the direct Service because it is not valid JSON.
            try {
                app(ReminderService::class)->create(auth()->user(), array_replace($this->body(), $change));
                $this->fail('Malformed UTF-8 accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        } else {
            $this->postJson('/api/reminders', array_replace($this->body(), $change), [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('reminders', 0);
    }

    public static function invalidInputs(): array
    {
        return ReminderRulesTest::invalidInputs();
    }

    #[DataProvider('targets')]
    public function test_foreign_targets_and_missing_ids_are_generic(?string $field): void
    {
        if ($field === null) {
            $field = 'task_id';
        }
        [, $task, $child, $session] = $this->fixture();
        $target = match ($field) {
            'task_id' => $task->id, 'subtask_id' => $child->id, default => $session->id
        };
        $this->actingAs(User::factory()->create());
        $first = $this->postJson('/api/reminders', [$field => $target, 'trigger_at' => $this->body()['trigger_at']])->assertUnprocessable();
        $second = $this->postJson('/api/reminders', [$field => PHP_INT_MAX, 'trigger_at' => $this->body()['trigger_at']])->assertUnprocessable();
        $this->assertSame($first->json(), $second->json());
        $this->assertStringNotContainsString((string) $target, $first->getContent());
    }

    #[DataProvider('ineligibleTargets')]
    public function test_ineligible_targets(string $kind): void
    {
        [, $task, $child, $session] = $this->fixture();
        $field = str_starts_with($kind, 'child') ? 'subtask_id' : (str_starts_with($kind, 'session') ? 'study_session_id' : 'task_id');
        match ($kind) {
            'task_deleted', 'child_parent_deleted' => $task->delete(),
            'task_completed', 'child_parent_completed' => $task->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 07:00:00']),
            'child_deleted' => $child->delete(),
            'child_completed' => $child->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 07:00:00']),
            'session_cancelled' => $session->update(['status' => StudySessionStatus::Cancelled]),
            'session_started' => $session->update(['starts_at' => '2026-10-19 08:00:00']),
            'session_bad_child' => $session->update(['subtask_id' => Subtask::factory()->create()->id]),
            'session_bad_owner' => $session->update(['task_id' => Task::factory()->create()->id]),
        };
        $id = match ($field) {
            'task_id' => $task->id, 'subtask_id' => $child->id, default => $session->id
        };
        $this->postJson('/api/reminders', [$field => $id, 'trigger_at' => $this->body()['trigger_at']])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->assertDatabaseCount('reminders', 0);
    }

    public static function ineligibleTargets(): array
    {
        return array_map(fn ($kind) => [$kind], ['task_deleted', 'task_completed', 'child_parent_deleted', 'child_parent_completed', 'child_deleted', 'child_completed', 'session_cancelled', 'session_started', 'session_bad_child', 'session_bad_owner']);
    }

    public function test_future_boundaries_and_full_precision(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['2026-10-19T07:59:59Z', '2026-10-19T08:00:00Z'] as $trigger) {
            $this->postJson('/api/reminders', [...$this->body(), 'trigger_at' => $trigger])->assertUnprocessable();
        }
        $this->postJson('/api/reminders', [...$this->body(), 'trigger_at' => '2026-10-19T08:00:01Z'])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:01Z'));
        $this->postJson('/api/reminders', [...$this->body(), 'trigger_at' => '2026-10-19T08:00:01Z'])->assertUnprocessable();
        $this->assertDatabaseCount('reminders', 1);
    }

    public function test_listing_pagination_order_filters_and_owner_isolation(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $later = Reminder::factory()->for($owner)->create(['message' => 'Later', 'trigger_at' => '2026-10-21 08:00:00']);
        $early = Reminder::factory()->for($owner)->create(['message' => 'Early', 'trigger_at' => '2026-10-20 08:00:00']);
        $cancelled = Reminder::factory()->for($owner)->create(['status' => ReminderStatus::Cancelled, 'trigger_at' => '2026-10-20 08:00:00']);
        $sent = Reminder::factory()->for($owner)->create(['status' => ReminderStatus::Sent, 'sent_at' => '2026-10-18 08:00:00', 'trigger_at' => '2026-10-18 08:00:00']);
        $foreign = Reminder::factory()->create();
        $before = $this->snapshot();
        $this->assertSame([$sent->id, $early->id, $cancelled->id, $later->id], $this->getJson('/api/reminders')->assertOk()->json('data.*.id'));
        $this->getJson('/api/reminders?per_page=2&page=2')->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.id', $cancelled->id);
        $this->assertSame([$early->id, $later->id], $this->getJson('/api/reminders?status=scheduled')->assertOk()->json('data.*.id'));
        $query = http_build_query(['trigger_from' => '2026-10-20T08:00:00Z', 'trigger_to' => '2026-10-20T08:00:00Z']);
        $this->assertSame([$early->id, $cancelled->id], $this->getJson('/api/reminders?'.$query)->assertOk()->json('data.*.id'));
        foreach (['?per_page=101', '?per_page=1.0', '?page=0', '?status=unknown', '?user_id=1', '?trigger_from=2026-10-20T08:00:00Z',
            '?trigger_from=2026-10-20T08:00:00Z&trigger_to=2026-10-19T08:00:00Z',
            '?trigger_from=2026-10-20T08:00:00Z&trigger_to=2028-10-20T08:00:00Z'] as $query) {
            $this->getJson('/api/reminders'.$query)->assertUnprocessable();
        }
        foreach ([$foreign->id, 999999] as $id) {
            $this->getJson('/api/reminders/'.$id)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            $this->patchJson('/api/reminders/'.$id, ['user_id' => null])->assertNotFound();
            $this->postJson('/api/reminders/'.$id.'/cancel', ['status' => null])->assertNotFound();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_update_preserves_identity_and_target_and_rejects_replacement(): void
    {
        [, $task] = $this->fixture();
        $id = $this->postJson('/api/reminders', ['task_id' => $task->id, 'trigger_at' => $this->body()['trigger_at']])->assertCreated()->json('data.id');
        foreach (['task_id', 'subtask_id', 'study_session_id', 'anchor', 'offset_minutes', 'status', 'sent_at', 'user_id', 'created_at', 'unknown'] as $field) {
            $this->patchJson('/api/reminders/'.$id, [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson('/api/reminders/'.$id, [])->assertUnprocessable();
        $this->patchJson('/api/reminders/'.$id, ['message' => 'Changed', 'trigger_at' => '2026-10-22T08:00:00Z'])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.task_id', $task->id)->assertJsonPath('data.message', 'Changed');
        $this->patchJson('/api/reminders/'.$id, ['message' => null])->assertOk()->assertJsonPath('data.message', null);
        $this->assertDatabaseCount('reminders', 1);
        $this->travelTo(CarbonImmutable::parse('2026-10-23T00:00:00Z'));
        $this->patchJson('/api/reminders/'.$id, ['message' => 'Overdue'])->assertUnprocessable();
        $this->postJson('/api/reminders/'.$id.'/cancel')->assertOk();
    }

    public function test_general_message_cannot_be_removed_and_exact_whitespace_is_preserved(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->postJson('/api/reminders', [...$this->body(), 'message' => '  Notes  ', 'task_id' => null, 'subtask_id' => null, 'study_session_id' => null])
            ->assertCreated()->assertJsonPath('data.message', '  Notes  ')->json('data.id');
        foreach ([null, '', '  ', [], 12, str_repeat('a', 501)] as $message) {
            $this->patchJson('/api/reminders/'.$id, ['message' => $message])->assertUnprocessable();
        }
        $this->patchJson('/api/reminders/'.$id, ['trigger_at' => ' 2026-10-22T08:00:00Z'])->assertUnprocessable();
        $this->patchJson('/api/reminders/'.$id, ['message' => str_repeat('ї', 500)])->assertOk();
    }

    public function test_malformed_json_and_scalar_action_payloads_are_rejected(): void
    {
        [$owner] = $this->fixture();
        $reminder = app(ReminderService::class)->create($owner, $this->body());
        foreach (['null', 'false', '42', '"message"', '{broken', '[1,2]'] as $body) {
            foreach (['/api/reminders', '/api/reminders/'.$reminder->id.'/cancel'] as $url) {
                $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertUnprocessable();
            }
        }
        $this->assertSame(ReminderStatus::Scheduled, $reminder->fresh()->status);
        $this->assertDatabaseCount('reminders', 1);
    }

    public function test_query_values_cannot_supply_or_override_writable_body_fields(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/reminders?'.http_build_query($this->body()))->assertUnprocessable();
        $id = $this->postJson('/api/reminders?user_id=999&trigger_at=invalid', $this->body())->assertCreated()->json('data.id');
        $this->patchJson('/api/reminders/'.$id.'?message=Changed')->assertUnprocessable();
        $this->patchJson('/api/reminders/'.$id.'?message=Changed', ['message' => 'Body value'])->assertOk()->assertJsonPath('data.message', 'Body value');
    }

    public function test_cancellation_preserves_relative_metadata_and_historical_immutability(): void
    {
        [$owner, $task] = $this->fixture();
        $reminder = Reminder::factory()->for($owner)->create(['task_id' => $task->id, 'anchor' => ReminderAnchor::TaskDeadline, 'offset_minutes' => -30, 'message' => 'Old']);
        $this->patchJson('/api/reminders/'.$reminder->id, ['message' => 'New'])->assertConflict();
        $before = $reminder->fresh()->getAttributes();
        $this->postJson('/api/reminders/'.$reminder->id.'/cancel', ['status' => null])->assertUnprocessable();
        $this->postJson('/api/reminders/'.$reminder->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(array_diff_key($before, array_flip(['status', 'updated_at'])), array_diff_key($reminder->fresh()->getAttributes(), array_flip(['status', 'updated_at'])));
        $this->postJson('/api/reminders/'.$reminder->id.'/cancel')->assertConflict();
        $this->patchJson('/api/reminders/'.$reminder->id, ['message' => 'New'])->assertConflict();
        $sent = Reminder::factory()->for($owner)->create(['status' => ReminderStatus::Sent, 'sent_at' => '2026-10-18 08:00:00', 'task_id' => $task->id]);
        $task->delete();
        $before = $this->snapshot();
        foreach ([$sent, $reminder] as $history) {
            $this->getJson('/api/reminders/'.$history->id)->assertOk();
            $this->patchJson('/api/reminders/'.$history->id, ['message' => 'Changed'])->assertConflict();
            $this->postJson('/api/reminders/'.$history->id.'/cancel')->assertConflict();
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('corruptions')]
    public function test_scheduled_corruption_fails_closed_without_private_details(string $kind): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $foreign = Task::factory()->create();
        $data = match ($kind) {
            'foreign_task' => ['task_id' => $foreign->id],
            'foreign_child' => ['subtask_id' => Subtask::factory()->for($foreign)->create()->id],
            'foreign_session' => ['study_session_id' => StudySession::factory()->for($foreign)->create()->id],
            'multiple' => ['task_id' => $task->id, 'subtask_id' => $child->id],
            'anchor' => ['task_id' => $task->id, 'anchor' => ReminderAnchor::SessionStart, 'offset_minutes' => 0],
            'general_anchor' => ['anchor' => ReminderAnchor::TaskDeadline, 'offset_minutes' => 0],
            'bad_session_child', 'bad_session_task' => ['study_session_id' => $session->id],
            default => [],
        };
        if ($kind === 'bad_session_child') {
            $session->update(['subtask_id' => Subtask::factory()->for($foreign)->create()->id]);
        } elseif ($kind === 'bad_session_task') {
            $session->update(['task_id' => $foreign->id]);
        }
        $reminder = Reminder::factory()->for($owner)->create($data);
        $before = $this->snapshot();
        $this->getJson('/api/reminders/'.$reminder->id)->assertStatus(500)->assertExactJson(['message' => 'The reminder could not be processed.']);
        $this->getJson('/api/reminders')->assertStatus(500);
        $this->patchJson('/api/reminders/'.$reminder->id, ['message' => 'Changed'])->assertStatus(500);
        $this->postJson('/api/reminders/'.$reminder->id.'/cancel')->assertStatus(500);
        $this->assertSame($before, $this->snapshot());
    }

    public static function corruptions(): array
    {
        return array_map(fn ($kind) => [$kind], ['foreign_task', 'foreign_child', 'foreign_session', 'multiple', 'anchor', 'general_anchor', 'bad_session_child', 'bad_session_task', 'general_empty']);
    }

    public function test_completed_targets_are_structurally_valid_until_f2_reconciliation(): void
    {
        [, $task] = $this->fixture();
        $id = $this->postJson('/api/reminders', ['task_id' => $task->id, 'trigger_at' => $this->body()['trigger_at']])->assertCreated()->json('data.id');
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk();
        $this->getJson('/api/reminders/'.$id)->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->patchJson('/api/reminders/'.$id, ['message' => 'Changed'])->assertOk();
        $this->postJson('/api/reminders/'.$id.'/cancel')->assertOk();
    }

    #[DataProvider('writeFailures')]
    public function test_failed_writes_and_final_clock_recheck_roll_back(string $action, string $failure): void
    {
        [$owner] = $this->fixture();
        $reminder = Reminder::factory()->for($owner)->create(['message' => 'Notes', 'trigger_at' => '2026-10-20 07:45:00']);
        $before = $this->snapshot();
        $event = $action === 'create' ? 'created' : 'updated';
        if ($failure === 'veto') {
            Reminder::saving(fn () => false);
        } else {
            Reminder::$event(function () use ($failure): void {
                if ($failure === 'clock') {
                    $this->travelTo(CarbonImmutable::parse('2026-10-20T07:45:00Z'));
                } else {
                    throw new RuntimeException('Injected write failure.');
                }
            });
        }
        try {
            $response = match ($action) {
                'create' => $this->postJson('/api/reminders', $this->body()),
                'update' => $this->patchJson('/api/reminders/'.$reminder->id, ['message' => 'Changed']),
                'cancel' => $this->postJson('/api/reminders/'.$reminder->id.'/cancel'),
            };
            $response->assertStatus($failure === 'clock' ? 422 : 500);
            $this->assertSame($before, $this->snapshot());
        } finally {
            Reminder::flushEventListeners();
        }
    }

    public static function writeFailures(): array
    {
        return [['create', 'throw'], ['update', 'throw'], ['cancel', 'throw'], ['create', 'veto'], ['update', 'veto'], ['cancel', 'veto'], ['create', 'clock'], ['update', 'clock']];
    }

    public function test_s3_and_stage_d_cancel_api_created_dependents_only(): void
    {
        [, $task, $child, $session] = $this->fixture();
        $ids = [];
        foreach (['task_id' => $task->id, 'subtask_id' => $child->id, 'study_session_id' => $session->id] as $field => $id) {
            $ids[$field] = $this->postJson('/api/reminders', [$field => $id, 'trigger_at' => $this->body()['trigger_at']])->assertCreated()->json('data.id');
        }
        $general = $this->postJson('/api/reminders', $this->body())->assertCreated()->json('data.id');
        $this->postJson('/api/study-sessions/'.$session->id.'/cancel')->assertOk();
        $this->assertSame(ReminderStatus::Cancelled, Reminder::find($ids['study_session_id'])->status);
        $this->assertSame(ReminderStatus::Scheduled, Reminder::find($ids['task_id'])->status);
        $this->deleteJson('/api/tasks/'.$task->id)->assertNoContent();
        foreach ($ids as $id) {
            $this->assertSame(ReminderStatus::Cancelled, Reminder::find($id)->status);
        }
        $this->assertSame(ReminderStatus::Scheduled, Reminder::find($general)->status);
        $this->assertFalse(app(ReminderDependencyService::class)->compatible(Reminder::find($general), auth()->user()));
    }

    #[DataProvider('sessionActions')]
    public function test_stage_d_cleanup_of_api_created_reminders(string $action): void
    {
        [, , , $session] = $this->fixture();
        $id = $this->postJson('/api/reminders', [...$this->body(), 'study_session_id' => $session->id])->assertCreated()->json('data.id');
        $before = Reminder::findOrFail($id)->getAttributes();
        if (in_array($action, ['complete', 'miss'], true)) {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        }
        $data = $action === 'reschedule' ? ['starts_at' => '2026-10-19T11:00:00Z', 'ends_at' => '2026-10-19T12:00:00Z'] : [];
        $this->postJson('/api/study-sessions/'.$session->id.'/'.$action, $data)->assertSuccessful();
        $after = Reminder::findOrFail($id)->getAttributes();
        $this->assertSame('cancelled', $after['status']);
        $this->assertSame(array_diff_key($before, array_flip(['status', 'updated_at'])), array_diff_key($after, array_flip(['status', 'updated_at'])));
        $this->getJson('/api/reminders/'.$id)->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public static function sessionActions(): array
    {
        return [['complete'], ['miss'], ['cancel'], ['reschedule']];
    }

    public function test_e2_atomically_cancels_api_created_reminder_and_preserves_general(): void
    {
        [$owner, , , $session] = $this->fixture();
        $id = $this->postJson('/api/reminders', [...$this->body(), 'study_session_id' => $session->id])->assertCreated()->json('data.id');
        $general = $this->postJson('/api/reminders', $this->body())->assertCreated()->json('data.id');
        Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        // Exclude the 08:00:00.5-to-09:00 fragment so E2 can propose a full hour.
        $owner->studyAvailabilityWindows()->update(['starts_at' => '09:00:00']);
        $preview = $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'])->assertOk()->json('data');
        $before = Reminder::findOrFail($id)->getAttributes();
        $this->postJson('/api/planning/replanning/confirm', ['preview_token' => $preview['preview_token']])->assertOk();
        $after = Reminder::findOrFail($id)->getAttributes();
        $this->assertSame('cancelled', $after['status']);
        $this->assertSame(array_diff_key($before, array_flip(['status', 'updated_at'])), array_diff_key($after, array_flip(['status', 'updated_at'])));
        $this->assertSame(ReminderStatus::Scheduled, Reminder::findOrFail($general)->status);
        $this->assertSame(1, StudySession::where('rescheduled_from_session_id', $session->id)->count());
    }

    public function test_session_start_is_rechecked_after_reminder_insert(): void
    {
        [, , , $session] = $this->fixture();
        $before = $this->snapshot();
        Reminder::created(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-19T09:00:00.000001Z')));
        try {
            $this->postJson('/api/reminders', [...$this->body(), 'study_session_id' => $session->id])->assertUnprocessable()->assertJsonValidationErrors('target');
            $this->assertSame($before, $this->snapshot());
        } finally {
            Reminder::flushEventListeners();
        }
    }

    public function test_mysql_datetime_ceiling_and_overdue_reads(): void
    {
        [$owner] = $this->fixture();
        $this->postJson('/api/reminders', [...$this->body(), 'trigger_at' => '9999-12-31T23:59:59Z'])->assertCreated()->assertJsonPath('data.trigger_at', '9999-12-31T23:59:59Z');
        $overdue = Reminder::factory()->for($owner)->create(['message' => 'Still scheduled']);
        $before = $this->snapshot();
        $this->getJson('/api/reminders/'.$overdue->id)->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->getJson('/api/reminders')->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/api/reminders/'.$overdue->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_service_rejects_ambient_transaction(): void
    {
        [$owner] = $this->fixture();
        DB::beginTransaction();
        try {
            $this->expectException(LogicException::class);
            app(ReminderService::class)->create($owner, $this->body());
        } finally {
            DB::rollBack();
        }
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 120]);
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00']);
        $this->actingAs($owner);

        return [$owner, $task, $child, $session];
    }

    private function body(): array
    {
        return ['message' => 'Review notes', 'trigger_at' => '2026-10-20T07:45:00Z'];
    }

    private function snapshot(array $except = []): array
    {
        $tables = array_column(DB::select('SHOW TABLES'), 'Tables_in_student_planner_testing');
        $result = [];
        foreach (array_diff($tables, ['migrations', ...$except]) as $table) {
            $result[$table] = DB::table($table)->orderBy(DB::getSchemaBuilder()->getColumnListing($table)[0])->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
