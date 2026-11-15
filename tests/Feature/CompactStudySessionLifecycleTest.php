<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Enums\TaskStatus;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\PlanningWorkSelectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactStudySessionLifecycleTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public static function actions(): array
    {
        return [0 => ['complete']];
    }

    #[DataProvider('completionEffort')]
    public function test_completion_preserves_history_and_uses_actual_first_accounting(?int $actual): void
    {
        [$user, $task, $source] = $this->fixture();
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 120]);
        $source->update(['subtask_id' => $child->id, 'ends_at' => '2026-10-19 10:00:01']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:30:00.987654Z'));
        $snapshot = $this->snapshot(['study_sessions', 'recovery_scan_states', 'jobs']);
        if ($actual === null) {
            $source->update(['status' => StudySessionStatus::Completed, 'completed_at' => '2026-10-19 10:30:00', 'actual_minutes' => null]);
        }
        $response = $actual === null ? $this->getJson('/api/study-sessions/'.$source->id) : $this->postJson($this->url($source, 'complete'), ['actual_minutes' => $actual]);
        $data = $response->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-19T10:30:00Z')->json('data');
        $this->assertSame($actual, $data['actual_minutes']);
        $this->assertSame(3601, $data['duration_seconds']);
        $this->assertSame('2026-10-19T09:00:00Z', $data['starts_at']);
        $this->assertSame($child->id, $data['subtask_id']);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('rescheduled_from_session_id', $data);
        $this->assertSame($snapshot, $this->snapshot(['study_sessions', 'recovery_scan_states', 'jobs']));
        $effort = app(PlanningWorkSelectionService::class)->select($user, $task->id, $child->id)['work_items'][0]['effort'];
        $this->assertSame($actual === null ? 3601 : $actual * 60, $effort['completed_credit_seconds']);
        $this->assertSame(0, $effort['future_reserved_seconds']);
        $this->assertSame([['start' => strtotime('2026-10-19T09:00:00Z'), 'end' => strtotime('2026-10-19T10:00:01Z')]], app(PlanningContextService::class)->load($user, '2026-10-19', '2026-10-19')['workload_sessions']);
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
        $this->assertSame(TaskStatus::Pending, $child->fresh()->status);
        $this->getJson('/api/study-sessions/'.$source->id)->assertOk()->assertExactJson(['data' => $data]);
    }

    public static function completionEffort(): array
    {
        return [0 => [null]];
    }

    #[DataProvider('timeBoundaries')]
    public function test_full_precision_lifecycle_boundaries(string $action, string $now, int $status): void
    {
        [, , $source] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse($now));
        $before = $this->snapshot();
        $this->postJson($this->url($source, $action), $action === 'complete' ? ['actual_minutes' => 45] : [])->assertStatus($status);
        if ($status === 409) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function timeBoundaries(): array
    {
        return [0 => ['complete', '2026-10-19T08:59:59.999999Z', 409]];
    }

    #[DataProvider('terminalActions')]
    public function test_all_terminal_states_reject_every_action_without_writes(string $status, string $action): void
    {
        [, , $source] = $this->fixture();
        $source->update(['status' => $status, 'completed_at' => $status === 'completed' ? '2026-10-19 08:00:00' : null, 'actual_minutes' => $status === 'completed' ? 15 : null]);
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, $action), $action === 'reschedule' ? $this->replacement() : ($action === 'complete' ? ['actual_minutes' => 45] : []))->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function terminalActions(): array
    {
        return ['completed complete' => ['completed', 'complete']];
    }

    #[DataProvider('invalidMinutes')]
    public function test_completion_http_rejects_non_integer_and_explicit_null(mixed $value): void
    {
        [, , $source] = $this->fixture();
        $snapshot = $this->snapshot();
        $this->call('POST', $this->url($source, 'complete'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['actual_minutes' => $value], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))->assertUnprocessable()->assertJsonValidationErrors('actual_minutes');
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function invalidMinutes(): array
    {
        return [0 => [null]];
    }

    #[DataProvider('actions')]
    public function test_arbitrary_fields_are_rejected_even_when_null(string $action): void
    {
        [, , $source] = $this->fixture();
        $fields = array_fill_keys(['user_id', 'task_id', 'subtask_id', 'status', 'completed_at', 'rescheduled_from_session_id', 'reminders', 'unknown'], null);
        $snapshot = $this->snapshot();
        $this->postJson($this->url($source, $action), [...$action === 'reschedule' ? $this->replacement() : ($action === 'complete' ? ['actual_minutes' => 45] : []), ...$fields])->assertUnprocessable()->assertJsonValidationErrors(array_keys($fields));
        $this->assertSame($snapshot, $this->snapshot());
    }

    #[DataProvider('historicalActions')]
    public function test_valid_owned_deleted_work_history_can_transition(string $action): void
    {
        [, $task, $source] = $this->fixture();
        $child = Subtask::factory()->for($task)->create();
        $source->update(['subtask_id' => $child->id]);
        $child->delete();
        $task->delete();
        $this->travelTo(CarbonImmutable::parse($action === 'cancel' ? '2026-10-19T08:00:00Z' : '2026-10-19T10:00:00Z'));
        $snapshot = $this->snapshot(['study_sessions', 'recovery_scan_states', 'jobs']);
        $this->postJson($this->url($source, $action), $action === 'complete' ? ['actual_minutes' => 45] : [])->assertOk()->assertJsonPath('data.task_id', $task->id)->assertJsonPath('data.subtask_id', $child->id);
        $this->assertSame($snapshot, $this->snapshot(['study_sessions', 'recovery_scan_states', 'jobs']));
    }

    public static function historicalActions(): array
    {
        return [0 => ['complete']];
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => 120]);
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 09:00:00', 'ends_at' => '2026-10-19 10:00:00']);
        foreach (range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00']);
        }
        $this->actingAs($user);

        return [$user, $task, $source];
    }

    private function url(StudySession $source, string $action): string
    {
        return '/api/study-sessions/'.$source->id.'/'.$action;
    }

    private function replacement(string $start = '11:00:00', string $end = '12:00:00'): array
    {
        return ['starts_at' => '2026-10-19T'.$start.'Z', 'ends_at' => '2026-10-19T'.$end.'Z'];
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
