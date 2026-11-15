<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Services\Tasks\SubtaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactSubtaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:20:30.987654Z'));
    }

    private function url(Task $task, Subtask $subtask, string $operation): string
    {
        return '/api/tasks/'.$task->id.'/subtasks/'.$subtask->id.'/'.$operation;
    }

    public static function operations(): array
    {
        return [0 => ['complete']];
    }

    private function childForOperation(string $operation): Subtask
    {
        return Subtask::factory()->create(['status' => $operation === 'reopen' ? TaskStatus::Completed : TaskStatus::Pending, 'completed_at' => $operation === 'reopen' ? '2026-10-10 12:00:00' : null])->refresh();
    }

    public function test_complete_is_late_utc_whole_second_and_preserves_exact_public_fields(): void
    {
        $subtask = Subtask::factory()->create(['title' => 'Report', 'description' => 'Keep', 'position' => 9, 'estimated_minutes' => 50, 'deadline_at' => '2026-10-10 12:00:00']);
        $task = $subtask->task;
        $user = $task->user;
        $user->update(['timezone' => 'America/Los_Angeles']);
        $before = $subtask->refresh()->getAttributes();
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $this->travelTo(CarbonImmutable::parse('2026-10-12T13:20:30.987654+03:00'));
            $this->actingAs($user, 'web')->postJson($this->url($task, $subtask, 'complete'), [])->assertOk()->assertExactJson(['data' => ['id' => $subtask->id, 'task_id' => $task->id, 'title' => 'Report', 'description' => 'Keep', 'position' => 9, 'status' => 'completed', 'estimated_minutes' => 50, 'deadline_at' => '2026-10-10T12:00:00Z', 'effective_deadline_at' => '2026-10-10T12:00:00Z', 'completed_at' => '2026-10-12T10:20:30Z']]);
            $this->assertSame('2026-10-12 10:20:30', $subtask->refresh()->getRawOriginal('completed_at'));
            $this->assertSame(Arr::except($before, ['status', 'completed_at', 'updated_at']), Arr::except($subtask->getAttributes(), ['status', 'completed_at', 'updated_at']));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_complete_reopen_and_complete_again_records_new_instant_and_regular_patch_preserves_lifecycle(): void
    {
        $subtask = Subtask::factory()->create();
        $task = $subtask->task;
        $this->actingAs($task->user, 'web')->postJson($this->url($task, $subtask, 'complete'))->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-12T10:20:30Z');
        $this->patchJson('/api/tasks/'.$task->id.'/subtasks/'.$subtask->id, ['title' => 'Edited', 'status' => 'pending', 'completed_at' => null])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-12T10:20:30Z');
        $this->postJson($this->url($task, $subtask, 'reopen'), [])->assertOk()->assertExactJson(['data' => ['id' => $subtask->id, 'task_id' => $task->id, 'title' => 'Edited', 'description' => null, 'position' => 1, 'status' => 'pending', 'estimated_minutes' => null, 'deadline_at' => null, 'effective_deadline_at' => null, 'completed_at' => null]]);
        $this->travelTo(CarbonImmutable::parse('2026-10-13T11:22:33.654321Z'));
        $this->postJson($this->url($task, $subtask, 'complete'))->assertOk()->assertJsonPath('data.completed_at', '2026-10-13T11:22:33Z');
        $this->assertSame('2026-10-13 11:22:33', $subtask->refresh()->getRawOriginal('completed_at'));
    }

    #[DataProvider('operations')]
    public function test_repeated_operation_preserves_all_persisted_data_without_update(string $operation): void
    {
        $child = $this->childForOperation($operation);
        $task = $child->task;
        $this->actingAs($task->user, 'web')->postJson($this->url($task, $child, $operation))->assertOk();
        $before = $child->refresh()->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T15:00:00Z'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson($this->url($task, $child, $operation))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($before, $child->refresh()->getAttributes());
        $this->assertSame([], array_values(array_filter($queries, fn ($query) => str_starts_with($query['query'], 'update '))));
    }

    #[DataProvider('parentStates')]
    public function test_parent_siblings_sessions_and_deadlines_are_preserved_while_reminders_cancel_once(bool $completedParent): void
    {
        $task = Task::factory()->create(['status' => $completedParent ? TaskStatus::Completed : TaskStatus::Pending, 'completed_at' => $completedParent ? '2026-10-10 12:00:00' : null, 'deadline_at' => '2026-10-11 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['deadline_at' => '2026-10-10 12:00:00']);
        $other = Subtask::factory()->for($task)->create(['position' => 2, 'status' => TaskStatus::Completed, 'completed_at' => '2026-10-09 12:00:00']);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id]);
        $reminder = Reminder::factory()->for($task->user)->create(['subtask_id' => $child->id]);
        $records = [$task, $other, $session];
        $reminderBefore = $reminder->refresh()->getAttributes();
        $snapshots = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $this->actingAs($task->user, 'web');
        foreach (['complete', 'reopen', 'complete'] as $operation) {
            $this->postJson($this->url($task, $child, $operation))->assertOk();
            foreach ($records as $i => $record) {
                $this->assertSame($snapshots[$i], $record->refresh()->getAttributes());
            }
            $after = $reminder->refresh()->getAttributes();
            $this->assertSame('cancelled', $after['status']);
            $this->assertSame(Arr::except($reminderBefore, ['status', 'updated_at']), Arr::except($after, ['status', 'updated_at']));
            if ($operation === 'complete' && ! isset($cancelledSnapshot)) {
                $cancelledSnapshot = $after;
            }
            $this->assertSame($cancelledSnapshot, $after);
            $this->assertSame('2026-10-10 12:00:00', $child->refresh()->getRawOriginal('deadline_at'));
        }
        $this->assertDatabaseCount('subtasks', 2);
        $this->assertDatabaseCount('study_sessions', 1);
        $this->assertDatabaseCount('reminders', 1);
    }

    public static function parentStates(): array
    {
        return [0 => [false]];
    }

    #[DataProvider('staleStates')]
    public function test_stale_child_models_do_not_determine_the_transition(string $operation, bool $staleCompleted, bool $currentCompleted): void
    {
        $child = Subtask::factory()->create(['status' => $staleCompleted ? TaskStatus::Completed : TaskStatus::Pending, 'completed_at' => $staleCompleted ? '2026-10-09 12:00:00' : null]);
        $task = $child->task;
        Subtask::findOrFail($child->id)->update(['title' => 'Latest', 'status' => $currentCompleted ? TaskStatus::Completed : TaskStatus::Pending, 'completed_at' => $currentCompleted ? '2026-10-11 12:00:00' : null]);
        $result = app(SubtaskService::class)->{$operation}($task->user, $task, $child);
        $this->assertSame('Latest', $result->title);
        $this->assertSame($operation === 'complete' ? TaskStatus::Completed : TaskStatus::Pending, $result->status);
        $this->assertSame($operation === 'reopen' ? null : ($currentCompleted ? '2026-10-11 12:00:00' : '2026-10-12 10:20:30'), $result->getRawOriginal('completed_at'));
    }

    public static function staleStates(): array
    {
        return [0 => ['complete', false, true]];
    }

    public function test_serialized_stale_requests_preserve_first_completion_then_allow_a_new_one(): void
    {
        $first = Subtask::factory()->create();
        $second = Subtask::findOrFail($first->id);
        $task = $first->task;
        $user = $task->user;
        $service = app(SubtaskService::class);
        $service->complete($user, $task, $first);
        $this->travelTo(CarbonImmutable::parse('2026-10-13T11:22:33Z'));
        $this->assertSame('2026-10-12 10:20:30', $service->complete($user, $task, $second)->getRawOriginal('completed_at'));
        $service->reopen($user, $task, $first);
        $this->assertSame('2026-10-13 11:22:33', $service->complete($user, $task, $second)->getRawOriginal('completed_at'));
    }
}
