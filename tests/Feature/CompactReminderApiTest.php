<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TestDatabase;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactReminderApiTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    #[DataProvider('targets')]
    public function test_create_each_target_or_general_preserves_all_other_tables(?string $field): void
    {
        [$owner, $task, $child, $session] = $this->fixture();
        $target = match ($field) {
            'task_id' => $task->id,
            'subtask_id' => $child->id,
            'study_session_id' => $session->id,
            default => null,
        };
        $before = $this->snapshot(['reminders']);
        $body = $field === null ? $this->body() : ['trigger_at' => $this->body()['trigger_at'], $field => $target, 'message' => null];
        $data = $this->postJson('/api/reminders', $body)->assertCreated()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.sent_at', null)->assertJsonPath('data.anchor', null)->assertJsonPath('data.offset_minutes', null)->json('data');
        $this->assertSame($before, $this->snapshot(['reminders']));
        $this->assertSame($owner->id, Reminder::findOrFail($data['id'])->user_id);
        $this->assertSame($body['trigger_at'], $data['trigger_at']);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->getJson('/api/reminders/'.$data['id'])->assertOk()->assertJsonPath('data.id', $data['id']);
    }

    public static function targets(): array
    {
        return [0 => [null]];
    }

    #[DataProvider('invalidInputs')]
    public function test_strict_body_validation(array $change, string $field): void
    {
        $this->actingAs(User::factory()->create());
        if (($change['message'] ?? null) === "\xff") {
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
        return [0 => [['trigger_at' => '2026-02-29T07:45:00Z'], 'trigger_at']];
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
            'task_id' => $task->id,
            'subtask_id' => $child->id,
            default => $session->id,
        };
        $this->postJson('/api/reminders', [$field => $id, 'trigger_at' => $this->body()['trigger_at']])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->assertDatabaseCount('reminders', 0);
    }

    public static function ineligibleTargets(): array
    {
        return [0 => ['task_deleted']];
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

    public function test_update_preserves_identity_and_target_and_rejects_replacement(): void
    {
        [, $task] = $this->fixture();
        $id = $this->postJson('/api/reminders', ['task_id' => $task->id, 'trigger_at' => $this->body()['trigger_at']])->assertCreated()->json('data.id');
        foreach (['task_id', 'subtask_id', 'study_session_id', 'anchor', 'offset_minutes', 'status', 'sent_at', 'user_id', 'created_at', 'unknown'] as $field) {
            $this->patchJson('/api/reminders/'.$id, [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson('/api/reminders/'.$id, [])->assertUnprocessable();
        $this->patchJson('/api/reminders/'.$id, ['message' => 'Changed', 'trigger_at' => '2026-10-22T08:00:00Z'])->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.task_id', $task->id)->assertJsonPath('data.message', 'Changed');
        $this->patchJson('/api/reminders/'.$id, ['message' => null])->assertOk()->assertJsonPath('data.message', null);
        $this->assertDatabaseCount('reminders', 1);
        $this->travelTo(CarbonImmutable::parse('2026-10-23T00:00:00Z'));
        $this->patchJson('/api/reminders/'.$id, ['message' => 'Overdue'])->assertUnprocessable();
        $this->postJson('/api/reminders/'.$id.'/cancel')->assertOk();
    }

    public function test_general_message_cannot_be_removed_and_exact_whitespace_is_preserved(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->postJson('/api/reminders', [...$this->body(), 'message' => '  Notes  ', 'task_id' => null, 'subtask_id' => null, 'study_session_id' => null])->assertCreated()->assertJsonPath('data.message', '  Notes  ')->json('data.id');
        foreach ([null, '', '  ', [], 12, str_repeat('a', 501)] as $message) {
            $this->patchJson('/api/reminders/'.$id, ['message' => $message])->assertUnprocessable();
        }
        $this->patchJson('/api/reminders/'.$id, ['trigger_at' => ' 2026-10-22T08:00:00Z'])->assertUnprocessable();
        $this->patchJson('/api/reminders/'.$id, ['message' => str_repeat('ї', 500)])->assertOk();
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
        $tables = array_column(DB::select('SHOW TABLES'), 'Tables_in_'.TestDatabase::name());
        $result = [];
        foreach (array_diff($tables, ['migrations', ...$except]) as $table) {
            $result[$table] = DB::table($table)->orderBy(DB::getSchemaBuilder()->getColumnListing($table)[0])->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
