<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Exceptions\ReminderIntegrityException;
use App\Models\Lesson;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderRules;
use App\Services\Reminders\ReminderSynchronizationService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\RelativeReminderRulesTest;

class RelativeReminderApiTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    #[DataProvider('creationTargets')]
    public function test_relative_creation_uses_exact_owned_anchor_and_changes_no_other_table(string $field, int $offset): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $id = $this->id($field, $task, $child, $session);
        $before = $this->snapshot(['reminders']);
        $expected = app(ReminderRules::class)->resolve(match ($field) {
            'task_id' => '2026-10-21T12:00:00Z', 'subtask_id' => '2026-10-20T12:00:00Z', default => '2026-10-19T09:00:00Z'
        }, $offset);
        $data = $this->postJson('/api/reminders', $this->body($field, $id, $offset))->assertCreated()
            ->assertJsonPath('data.anchor', $this->anchor($field))->assertJsonPath('data.offset_minutes', $offset)
            ->assertJsonPath('data.trigger_at', $expected)->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.sent_at', null)->json('data');
        $this->assertSame($before, $this->snapshot(['reminders']));
        $this->assertSame($owner->id, Reminder::findOrFail($data['id'])->user_id);
        $this->getJson('/api/reminders/'.$data['id'])->assertOk()->assertJsonPath('data.trigger_at', $expected);
        $this->getJson('/api/reminders?status=scheduled')->assertOk()->assertJsonPath('data.0.id', $data['id']);
    }

    public static function creationTargets(): array
    {
        $cases = [];
        foreach (ReminderRules::TARGETS as $field) {
            foreach ([-15, 0, 30] as $offset) {
                $cases[] = [$field, $offset];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidOffsets')]
    public function test_invalid_offset_types_and_bounds_on_create_and_patch(mixed $offset): void
    {
        [, $task] = $this->fixture();
        $id = $this->create('task_id', $task->id);
        $before = $this->snapshot();
        $this->postJson('/api/reminders', $this->body('task_id', $task->id, $offset), [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable()->assertJsonValidationErrors('offset_minutes');
        $this->patchJson('/api/reminders/'.$id, ['offset_minutes' => $offset], [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable()->assertJsonValidationErrors('offset_minutes');
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidOffsets(): array
    {
        return array_map(fn ($case) => [$case[0]], array_filter(RelativeReminderRulesTest::offsets(), fn ($case) => ! $case[1]));
    }

    public function test_signed_boundaries_are_stored_without_truncation(): void
    {
        [, $task] = $this->fixture();
        $task->update(['deadline_at' => '2026-11-20 12:00:00']);
        foreach ([-32768, 32767] as $offset) {
            $id = $this->create('task_id', $task->id, $offset);
            $this->assertSame($offset, Reminder::findOrFail($id)->offset_minutes);
        }
        $this->assertSame('smallint', Schema::getColumnType('reminders', 'offset_minutes', true));
    }

    public function test_modes_and_anchor_identity_are_strict(): void
    {
        [, $task, $child, $session] = $this->fixture();
        foreach (ReminderRules::TARGETS as $field) {
            $id = $this->id($field, $task, $child, $session);
            foreach (['task_deadline', 'subtask_deadline', 'session_start'] as $anchor) {
                if ($anchor !== $this->anchor($field)) {
                    $this->postJson('/api/reminders', [...$this->body($field, $id), 'anchor' => $anchor])->assertUnprocessable()->assertJsonValidationErrors('anchor');
                }
            }
        }
        $body = $this->body('task_id', $task->id);
        foreach ([['trigger_at' => null], ['trigger_at' => '2026-10-20T08:00:00Z'], ['anchor' => null], ['anchor' => []], ['anchor' => true], ['anchor' => 'unknown'], ['user_id' => null], ['status' => null], ['sent_at' => null], ['created_at' => null], ['unknown' => null], ['subtask_id' => $child->id]] as $change) {
            $this->postJson('/api/reminders', array_replace($body, $change))->assertUnprocessable();
        }
        $missingOffset = $body;
        unset($missingOffset['offset_minutes']);
        $this->postJson('/api/reminders', $missingOffset)->assertUnprocessable()->assertJsonValidationErrors('offset_minutes');
        unset($body['anchor']);
        $this->postJson('/api/reminders', $body)->assertUnprocessable()->assertJsonValidationErrors('anchor');
        $this->postJson('/api/reminders', ['message' => 'General', 'anchor' => 'task_deadline', 'offset_minutes' => 0])->assertUnprocessable()->assertJsonValidationErrors('target');
        $id = $this->create('task_id', $task->id);
        foreach (['anchor', ...ReminderRules::TARGETS, 'user_id', 'status', 'sent_at', 'updated_at', 'trigger_at'] as $field) {
            $this->patchJson('/api/reminders/'.$id, [$field => null])->assertUnprocessable();
        }
        $absolute = $this->postJson('/api/reminders', ['task_id' => $task->id, 'trigger_at' => '2026-10-20T08:00:00Z'])->assertCreated()->json('data.id');
        $this->patchJson('/api/reminders/'.$absolute, ['offset_minutes' => 0])->assertUnprocessable();
    }

    #[DataProvider('unavailableTargets')]
    public function test_unavailable_target_creation_is_owner_safe(string $field, string $state): void
    {
        [, $task, $child, $session] = $this->fixture();
        $target = match ($field) {
            'task_id' => $task, 'subtask_id' => $child, default => $session
        };
        $id = $target->id;
        match ($state) {
            'foreign' => $this->actingAs(User::factory()->create()),
            'missing' => $id = 999999,
            'deleted' => $task->delete(),
            'completed' => $task->update(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-19 07:00:00']),
        };
        $before = $this->snapshot();
        $this->postJson('/api/reminders', $this->body($field, $id))->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->assertSame($before, $this->snapshot());
    }

    public static function unavailableTargets(): array
    {
        $cases = [];
        foreach (ReminderRules::TARGETS as $field) {
            foreach (['foreign', 'missing', 'deleted', 'completed'] as $state) {
                $cases[] = [$field, $state];
            }
        }

        return $cases;
    }

    public function test_child_uses_own_deadline_without_parent_fallback_and_null_anchors_reject(): void
    {
        [, $task, $child] = $this->fixture();
        $child->update(['deadline_at' => null]);
        $this->postJson('/api/reminders', $this->body('subtask_id', $child->id))->assertUnprocessable()->assertJsonValidationErrors('anchor');
        $task->update(['deadline_at' => null]);
        $this->postJson('/api/reminders', $this->body('task_id', $task->id))->assertUnprocessable()->assertJsonValidationErrors('anchor');
        $child->update(['deadline_at' => '2026-10-20 12:00:00']);
        $this->create('subtask_id', $child->id);
    }

    #[DataProvider('creationClockBoundaries')]
    public function test_future_equality_and_datetime_overflow(string $anchor, int $offset, bool $valid): void
    {
        [, $task, $child] = $this->fixture();
        $child->update(['deadline_at' => null]);
        $task->update(['deadline_at' => str_replace(['T', 'Z'], [' ', ''], $anchor)]);
        $response = $this->postJson('/api/reminders', $this->body('task_id', $task->id, $offset));
        $valid ? $response->assertCreated() : $response->assertUnprocessable();
    }

    public static function creationClockBoundaries(): array
    {
        return [['2026-10-19T08:00:00Z', 0, false], ['2026-10-19T08:01:00Z', -1, false], ['2026-10-19T08:00:01Z', 0, true],
            ['1000-01-01T00:00:00Z', -1, false], ['9999-12-31T23:59:59Z', 1, false], ['9999-12-31T23:59:59Z', 0, true]];
    }

    public function test_relative_offset_and_message_edits_reload_current_anchor_and_preserve_identity(): void
    {
        [, $task] = $this->fixture();
        $id = $this->create('task_id', $task->id);
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-22T12:00:00Z'])->assertOk();
        $before = Reminder::findOrFail($id)->getAttributes();
        $this->patchJson('/api/reminders/'.$id, ['message' => 'New', 'offset_minutes' => 30])->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.offset_minutes', 30)->assertJsonPath('data.trigger_at', '2026-10-22T12:30:00Z');
        $after = Reminder::findOrFail($id)->getAttributes();
        foreach (['id', 'task_id', 'anchor', 'status', 'sent_at', 'created_at'] as $key) {
            $this->assertSame($before[$key], $after[$key]);
        }
        $this->patchJson('/api/reminders/'.$id, ['offset_minutes' => -32768])->assertUnprocessable();
        $this->patchJson('/api/reminders/'.$id, ['message' => null])->assertOk()->assertJsonPath('data.trigger_at', '2026-10-22T12:30:00Z');
        $this->postJson('/api/reminders/'.$id.'/cancel')->assertOk();
        $this->patchJson('/api/reminders/'.$id, ['offset_minutes' => 0])->assertConflict();
        $this->postJson('/api/reminders/'.$id.'/cancel')->assertConflict();
    }

    #[DataProvider('deadlineOutcomes')]
    public function test_deadline_synchronization_is_atomic_and_preserves_metadata(string $field, ?string $deadline, int $offset, ?string $expected): void
    {
        [$owner, $task, $child] = $this->fixture();
        // Keep reciprocal Task/Subtask deadline validation independent of the reminder outcome.
        $task->update(['deadline_at' => $field === 'subtask_id' ? '9999-12-31 23:59:59' : '2026-11-20 12:00:00']);
        if ($field === 'task_id') {
            $child->update(['deadline_at' => null]);
        }
        $target = $field === 'task_id' ? $task : $child;
        $id = $this->create($field, $target->id, $offset);
        $absolute = $this->postJson('/api/reminders', [$field => $target->id, 'trigger_at' => '2026-10-23T08:00:00Z'])->assertCreated()->json('data.id');
        $sent = Reminder::factory()->for($owner)->create([$field => $target->id, 'anchor' => $this->anchor($field), 'offset_minutes' => $offset, 'status' => ReminderStatus::Sent, 'sent_at' => '2026-10-18 08:00:00']);
        $cancelled = Reminder::factory()->for($owner)->create([$field => $target->id, 'anchor' => $this->anchor($field), 'offset_minutes' => $offset, 'status' => ReminderStatus::Cancelled]);
        $preserved = array_map(fn ($id) => Reminder::findOrFail($id)->getAttributes(), [$absolute, $sent->id, $cancelled->id]);
        $before = Reminder::findOrFail($id)->getAttributes();
        $this->patchJson($this->targetUrl($task, $field === 'subtask_id' ? $child : null), ['deadline_at' => $deadline])->assertOk();
        $after = Reminder::findOrFail($id)->getAttributes();
        $this->assertSame($expected === null ? 'cancelled' : 'scheduled', $after['status']);
        $this->assertSame($expected ?? $before['trigger_at'], $after['trigger_at']);
        foreach (['id', $field, 'anchor', 'offset_minutes', 'message', 'sent_at', 'created_at'] as $key) {
            $this->assertSame($before[$key], $after[$key]);
        }
        $this->assertSame($preserved, array_map(fn ($id) => Reminder::findOrFail($id)->getAttributes(), [$absolute, $sent->id, $cancelled->id]));
    }

    public static function deadlineOutcomes(): array
    {
        $cases = [];
        foreach (['task_id', 'subtask_id'] as $field) {
            foreach ([['2026-10-22T12:00:00Z', -60, '2026-10-22 11:00:00'], [null, -60, null], ['2026-10-18T12:00:00Z', 0, null],
                ['2026-10-19T08:15:00Z', -60, null], ['2026-10-19T07:45:00Z', 30, '2026-10-19 08:15:00'],
                ['2026-10-19T08:00:00Z', 0, null], ['1000-01-01T00:00:00Z', -1, null], ['9999-12-31T23:59:59Z', 1, null]] as $case) {
                $cases[] = [$field, ...$case];
            }
        }

        return $cases;
    }

    public function test_parent_deadline_change_never_reanchors_child_reminder(): void
    {
        [, $task, $child] = $this->fixture();
        $id = $this->create('subtask_id', $child->id);
        $before = Reminder::findOrFail($id)->getAttributes();
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => null])->assertOk();
        $this->assertSame($before, Reminder::findOrFail($id)->getAttributes());
        $this->patchJson('/api/tasks/'.$task->id.'/subtasks/'.$child->id, ['deadline_at' => '2026-10-23T12:00:00Z'])->assertOk();
        $this->getJson('/api/reminders/'.$id)->assertOk()->assertJsonPath('data.trigger_at', '2026-10-23T11:45:00Z');
    }

    #[DataProvider('completionUnits')]
    public function test_completion_cancels_exact_ineligible_targets_without_altering_sessions_or_resurrecting(bool $parent): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $sibling = Subtask::factory()->for($task)->create(['position' => 2, 'deadline_at' => '2026-10-20 12:00:00']);
        $rootSession = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 11:00:00', 'ends_at' => '2026-10-19 12:00:00']);
        $ids = [];
        foreach (['task_id' => $task->id, 'subtask_id' => $child->id, 'study_session_id' => $session->id] as $field => $id) {
            $ids[$field] = $this->create($field, $id);
        }
        $siblingReminder = $this->create('subtask_id', $sibling->id);
        $rootReminder = $this->create('study_session_id', $rootSession->id);
        $absolute = $this->postJson('/api/reminders', ['subtask_id' => $child->id, 'trigger_at' => '2026-10-23T08:00:00Z'])->assertCreated()->json('data.id');
        $general = $this->postJson('/api/reminders', ['message' => 'General', 'trigger_at' => '2026-10-23T08:00:00Z'])->assertCreated()->json('data.id');
        $sent = Reminder::factory()->for($owner)->create(['task_id' => $task->id, 'status' => ReminderStatus::Sent, 'sent_at' => '2026-10-18 08:00:00']);
        $sessionBefore = [$session->fresh()->getAttributes(), $rootSession->fresh()->getAttributes()];
        $allBefore = Reminder::orderBy('id')->get()->keyBy('id');
        $this->postJson($this->targetUrl($task, $parent ? null : $child).'/complete')->assertOk();
        foreach ([$ids['subtask_id'], $ids['study_session_id'], $absolute] as $id) {
            $this->assertCancelledMetadata($allBefore[$id]);
        }
        foreach ([$ids['task_id'], $siblingReminder, $rootReminder] as $id) {
            if ($parent) {
                $this->assertCancelledMetadata($allBefore[$id]);
            } else {
                $this->assertSame($allBefore[$id]->getAttributes(), Reminder::findOrFail($id)->getAttributes());
            }
        }
        $this->assertSame($sessionBefore, [$session->fresh()->getAttributes(), $rootSession->fresh()->getAttributes()]);
        foreach ([$sent->id, $general] as $id) {
            $this->assertSame($allBefore[$id]->getAttributes(), Reminder::findOrFail($id)->getAttributes());
        }
        $history = $this->snapshot();
        $this->postJson($this->targetUrl($task, $parent ? null : $child).'/complete')->assertOk();
        $this->assertSame($history, $this->snapshot());
        $remindersBefore = Reminder::orderBy('id')->get()->map->getAttributes()->all();
        $this->postJson($this->targetUrl($task, $parent ? null : $child).'/reopen')->assertOk();
        $this->assertSame($remindersBefore, Reminder::orderBy('id')->get()->map->getAttributes()->all());
        $this->create($parent ? 'task_id' : 'subtask_id', $parent ? $task->id : $child->id);
    }

    public static function completionUnits(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('failureCases')]
    public function test_failed_reconciliation_rolls_back_parent_and_entire_reminder_batch(string $operation, string $failure): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $parent = ! str_starts_with($operation, 'child');
        $field = $parent ? 'task_id' : 'subtask_id';
        $id = $parent ? $task->id : $child->id;
        $this->create($field, $id);
        $this->create($field, $id, -30);
        $url = $this->targetUrl($task, $parent ? null : $child);
        if ($failure === 'foreign') {
            Reminder::factory()->create([$field => $id, 'anchor' => $this->anchor($field), 'offset_minutes' => 0]);
        } elseif ($failure === 'multiple') {
            Reminder::factory()->for($owner)->create(['task_id' => $task->id, 'subtask_id' => $child->id]);
        } elseif ($failure === 'session') {
            $session->update(['task_id' => Task::factory()->create()->id]);
            Reminder::factory()->for($owner)->create(['study_session_id' => $session->id]);
        }
        $before = $this->snapshot();
        $writes = 0;
        if ($failure === 'veto') {
            Reminder::saving(fn () => false);
        } elseif ($failure === 'throw') {
            Reminder::updated(function () use (&$writes): void {
                if (++$writes === 2) {
                    throw new RuntimeException('Failure after second reminder write.');
                }
            });
        } elseif ($failure === 'target_veto') {
            ($parent ? Task::class : Subtask::class)::saving(fn () => false);
        }
        try {
            $response = str_ends_with($operation, 'deadline') ? $this->patchJson($url, ['deadline_at' => '2026-10-21T10:00:00Z']) : $this->postJson($url.'/complete');
            $response->assertStatus(500);
            if ($failure !== 'target_veto') {
                $response->assertExactJson(['message' => 'The reminder dependencies could not be processed.']);
            }
            $this->assertSame($before, $this->snapshot());
        } finally {
            Reminder::flushEventListeners();
            Task::flushEventListeners();
            Subtask::flushEventListeners();
        }
    }

    public static function failureCases(): array
    {
        $cases = [];
        foreach (['task_deadline', 'child_deadline', 'task_complete', 'child_complete'] as $op) {
            foreach (['foreign', 'multiple', 'veto', 'throw', 'target_veto'] as $failure) {
                $cases[] = [$op, $failure];
            }
        }
        $cases[] = ['task_complete', 'session'];
        $cases[] = ['child_complete', 'session'];

        return $cases;
    }

    public function test_final_batch_clock_cancels_with_original_trigger_and_get_does_not_sync(): void
    {
        [, $task, $child] = $this->fixture();
        $child->update(['deadline_at' => null]);
        $id = $this->create('task_id', $task->id, 0);
        $before = Reminder::findOrFail($id)->getAttributes();
        Reminder::updated(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:02Z')));
        try {
            $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-19T08:00:01Z'])->assertOk();
            $this->assertCancelledMetadata(Reminder::findOrFail($id)->setRawAttributes($before, true));
        } finally {
            Reminder::flushEventListeners();
        }
        $child->update(['deadline_at' => '2026-10-20 12:00:00']);
        $task->refresh()->update(['deadline_at' => '2026-10-21 12:00:00']);
        $live = $this->create('task_id', $task->id);
        $task->update(['deadline_at' => null]); // Noncooperating SQL/model write: GET remains advisory.
        $snapshot = $this->snapshot();
        $this->getJson('/api/reminders/'.$live)->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->getJson('/api/reminders')->assertOk();
        $this->assertSame($snapshot, $this->snapshot());
        $this->patchJson('/api/reminders/'.$live, ['offset_minutes' => 0])->assertUnprocessable();
    }

    #[DataProvider('sessionActions')]
    public function test_stage_d_and_s3_cleanup_keep_exact_relative_identity(string $action): void
    {
        [, $task, $child, $session] = $this->fixture();
        $id = $this->create('study_session_id', $session->id);
        $before = Reminder::findOrFail($id);
        if (in_array($action, ['complete', 'miss'], true)) {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        }
        if ($action === 'task_delete') {
            $this->deleteJson('/api/tasks/'.$task->id)->assertNoContent();
        } elseif ($action === 'child_delete') {
            $this->deleteJson($this->targetUrl($task, $child))->assertNoContent();
        } else {
            $body = $action === 'reschedule' ? ['starts_at' => '2026-10-19T11:00:00Z', 'ends_at' => '2026-10-19T12:00:00Z'] : [];
            $this->postJson('/api/study-sessions/'.$session->id.'/'.$action, $body)->assertSuccessful();
        }
        $this->assertCancelledMetadata($before);
        $this->assertDatabaseCount('reminders', 1);
        if ($action === 'reschedule') {
            $replacement = StudySession::where('rescheduled_from_session_id', $session->id)->firstOrFail();
            $new = $this->create('study_session_id', $replacement->id);
            $this->assertSame($replacement->id, Reminder::findOrFail($new)->study_session_id);
            $this->assertSame('2026-10-19 10:45:00', Reminder::findOrFail($new)->getRawOriginal('trigger_at'));
        }
    }

    public static function sessionActions(): array
    {
        return [['complete'], ['miss'], ['cancel'], ['reschedule'], ['task_delete'], ['child_delete']];
    }

    public function test_e2_retires_relative_source_reminder_without_cloning_to_successor(): void
    {
        [$owner, , , $session] = $this->fixture();
        $id = $this->create('study_session_id', $session->id);
        $before = Reminder::findOrFail($id);
        Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        $token = $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'])->assertOk()->json('data.preview_token');
        $this->postJson('/api/planning/replanning/confirm', ['preview_token' => $token])->assertOk();
        $this->assertCancelledMetadata($before);
        $this->assertDatabaseCount('reminders', 1);
        $replacement = StudySession::where('rescheduled_from_session_id', $session->id)->firstOrFail();
        $this->create('study_session_id', $replacement->id);
    }

    #[DataProvider('calendarAnchors')]
    public function test_kyiv_calendar_boundaries_use_utc_instants(string $now, string $anchor, int $offset, string $expected): void
    {
        [, $task, $child] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse($now));
        $task->user->update(['timezone' => 'Europe/Kyiv']);
        $child->update(['deadline_at' => null]);
        $task->update(['deadline_at' => str_replace(['T', 'Z'], [' ', ''], $anchor)]);
        $id = $this->create('task_id', $task->id, $offset);
        $this->getJson('/api/reminders/'.$id)->assertOk()->assertJsonPath('data.trigger_at', $expected);
    }

    public static function calendarAnchors(): array
    {
        return [['2026-03-28T00:00:00Z', '2026-03-29T00:30:00Z', 60, '2026-03-29T01:30:00Z'],
            ['2026-10-24T00:00:00Z', '2026-10-25T00:30:00Z', 60, '2026-10-25T01:30:00Z'],
            ['2026-12-30T00:00:00Z', '2026-12-31T23:45:00Z', 30, '2027-01-01T00:15:00Z']];
    }

    public function test_sync_requires_existing_transaction_and_foreign_target_apis_are_inaccessible(): void
    {
        [$owner, $task] = $this->fixture();
        $this->actingAs(User::factory()->create());
        $before = $this->snapshot();
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => null])->assertNotFound();
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertNotFound();
        $this->assertSame($before, $this->snapshot());
        $this->expectException(LogicException::class);
        app(ReminderSynchronizationService::class)->deadlineChanged($owner, $task);
    }

    public function test_completion_locks_owner_work_sessions_then_sorted_reminders(): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $this->create('task_id', $task->id);
        $this->create('subtask_id', $child->id);
        $this->create('study_session_id', $session->id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(TaskService::class)->complete($owner, $task);
            $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($query) => str_contains($query, 'for update'))->values()->all();
            $tables = array_map(function ($query) {
                preg_match('/from `([^`]+)`/', $query, $match);

                return $match[1];
            }, $queries);
            $this->assertSame(['users', 'tasks', 'subtasks', 'study_sessions', 'reminders'], $tables);
            foreach (array_slice($queries, 2) as $query) {
                $this->assertStringContainsString('order by `id` asc', $query);
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_sync_rejects_forged_owner_or_child_context_before_dependency_locks(): void
    {
        [$owner, $task] = $this->fixture();
        $foreign = User::factory()->create();
        $foreignChild = Subtask::factory()->create();
        foreach ([[$foreign, $task, null], [$owner, $task, $foreignChild]] as [$caller, $parent, $child]) {
            DB::beginTransaction();
            User::whereKey($caller->id)->lockForUpdate()->firstOrFail();
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                app(ReminderSynchronizationService::class)->completed($caller, $parent, $child);
                $this->fail('Invalid caller context was accepted.');
            } catch (ReminderIntegrityException) {
                $this->assertSame([], DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::rollBack();
            }
        }
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 120, 'deadline_at' => '2026-10-21 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120, 'deadline_at' => '2026-10-20 12:00:00']);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);
        $this->actingAs($owner);

        return [$owner, $task, $child, $session];
    }

    private function body(string $field, int $id, mixed $offset = -15): array
    {
        return [$field => $id, 'anchor' => $this->anchor($field), 'offset_minutes' => $offset, 'message' => 'Relative reminder'];
    }

    private function anchor(string $field): string
    {
        return app(ReminderRules::class)->anchorFor($field)->value;
    }

    private function id(string $field, Task $task, Subtask $child, StudySession $session): int
    {
        return match ($field) {
            'task_id' => $task->id, 'subtask_id' => $child->id, default => $session->id
        };
    }

    private function create(string $field, int $id, int $offset = -15): int
    {
        return $this->postJson('/api/reminders', $this->body($field, $id, $offset))->assertCreated()->json('data.id');
    }

    private function targetUrl(Task $task, ?Subtask $child = null): string
    {
        return '/api/tasks/'.$task->id.($child === null ? '' : '/subtasks/'.$child->id);
    }

    private function assertCancelledMetadata(Reminder $before): void
    {
        $after = $before->fresh();
        $this->assertSame(ReminderStatus::Cancelled, $after->status);
        foreach (['id', 'task_id', 'subtask_id', 'study_session_id', 'trigger_at', 'anchor', 'offset_minutes', 'message', 'sent_at', 'created_at'] as $field) {
            $this->assertSame($before->getRawOriginal($field), $after->getRawOriginal($field));
        }
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
