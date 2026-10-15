<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Progress\ProgressStatisticsService;
use App\Services\Reminders\ReminderDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgressStatisticsIntegrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(int $minutes = 60): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => $minutes]);
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);
        $this->actingAs($user, 'web');

        return [$user, $task];
    }

    private function statistics(): array
    {
        $before = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->getJson('/api/statistics/progress?start_date=2026-10-19&end_date=2026-10-19')->assertOk()->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertSame($before, $this->snapshot());

        return $data;
    }

    public function test_stage_c_confirmed_plan_is_scheduled_time_without_invented_actual_effort(): void
    {
        [, $task] = $this->fixture();
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 60]);
        $preview = $this->postJson('/api/planning/study-plans/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19',
            'task_id' => $task->id, 'subtask_id' => $child->id])->assertOk()->json('data');
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $preview['preview_token']])->assertCreated();
        $data = $this->statistics();
        $this->assertSame(['count' => 1, 'scheduled_seconds' => 3600], $data['study_sessions']['started_in_period']['planned']);
        $this->assertSame(0, $data['study_sessions']['started_in_period']['completed_actual_minutes']);
        $this->assertSame(1, $data['tasks']['current']['pending']);
        $this->assertSame(1, $data['subtasks']['current']['pending']);
    }

    #[DataProvider('lifecycleActions')]
    public function test_stage_d_actual_actions_are_reflected_without_changing_work_or_history(string $action, ?int $actual): void
    {
        [, $task] = $this->fixture(120);
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        if (in_array($action, ['complete', 'miss'], true)) {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T11:00:00.123456Z'));
        }
        $body = match ($action) {
            'complete' => $actual === null ? [] : ['actual_minutes' => $actual],
            'reschedule' => ['starts_at' => '2026-10-19T12:00:00Z', 'ends_at' => '2026-10-19T13:00:00Z'],
            default => [],
        };
        $this->postJson('/api/study-sessions/'.$source->id.'/'.$action, $body)->assertStatus($action === 'reschedule' ? 201 : 200);
        $data = $this->statistics();
        $sessions = $data['study_sessions']['started_in_period'];
        $status = match ($action) {
            'complete' => 'completed', 'miss' => 'missed', 'cancel' => 'cancelled', 'reschedule' => 'rescheduled'
        };
        $this->assertSame(['count' => 1, 'scheduled_seconds' => 3600], $sessions[$status]);
        $this->assertSame($action === 'complete' ? ($actual ?? 0) : 0, $sessions['completed_actual_minutes']);
        $this->assertSame((int) ($action === 'complete' && $actual === null), $sessions['completed_without_actual_minutes']);
        $this->assertSame((int) ($action === 'reschedule'), $sessions['planned']['count']);
        $this->assertSame(0, $data['tasks']['completed_in_period']['total']);
        if ($action === 'reschedule') {
            $this->assertSame($source->id, StudySession::where('rescheduled_from_session_id', $source->id)->sole()->rescheduled_from_session_id);
        }
    }

    public static function lifecycleActions(): array
    {
        return [['complete', null], ['complete', 25], ['miss', null], ['cancel', null], ['reschedule', null]];
    }

    public function test_stage_e2_atomic_replanning_history_is_separate_from_completed_effort(): void
    {
        [, $task] = $this->fixture(120);
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        Lesson::factory()->for($task->user)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $preview = $this->postJson('/api/planning/replanning/preview', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'])
            ->assertOk()->json('data');
        $this->postJson('/api/planning/replanning/confirm', ['preview_token' => $preview['preview_token']])->assertOk();
        $sessions = $this->statistics()['study_sessions']['started_in_period'];
        $this->assertSame(['count' => 1, 'scheduled_seconds' => 3600], $sessions['rescheduled']);
        $this->assertSame(['count' => 1, 'scheduled_seconds' => 3600], $sessions['planned']);
        $this->assertSame(['count' => 0, 'scheduled_seconds' => 0], $sessions['completed']);
        $this->assertSame(0, $sessions['completed_actual_minutes']);
        $this->assertSame($source->id, StudySession::where('rescheduled_from_session_id', $source->id)->sole()->rescheduled_from_session_id);
    }

    public function test_f1_f2_f3a_reminders_delivery_and_inbox_read_state_survive_statistics_reads(): void
    {
        [$user, $task] = $this->fixture();
        $child = Subtask::factory()->for($task)->create(['deadline_at' => '2026-10-20 12:00:00']);
        $absolute = $this->postJson('/api/reminders', ['task_id' => $task->id, 'trigger_at' => '2026-10-19T08:00:01Z'])
            ->assertCreated()->json('data.id');
        $relative = $this->postJson('/api/reminders', ['subtask_id' => $child->id, 'anchor' => 'subtask_deadline', 'offset_minutes' => -15])
            ->assertCreated()->json('data.id');
        $this->patchJson('/api/tasks/'.$task->id.'/subtasks/'.$child->id, ['deadline_at' => '2026-10-21T12:00:00Z'])->assertOk();
        $cancelled = $this->postJson('/api/reminders', ['message' => 'Cancel me', 'trigger_at' => '2026-10-19T08:00:02Z'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/reminders/'.$cancelled.'/cancel')->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:01.500000Z'));
        $this->assertSame(['status' => 'sent'], app(ReminderDeliveryService::class)->process($user->id, $absolute));
        $receipt = $user->notifications()->sole();
        $first = $this->statistics();
        $this->assertNull($receipt->fresh()->read_at);
        $this->postJson('/api/notifications/'.$receipt->id.'/read')->assertOk();
        $this->assertNotNull($receipt->fresh()->read_at);
        $this->assertSame($first, $this->statistics());
        $this->getJson('/api/reminders/'.$relative)->assertOk()->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.trigger_at', '2026-10-21T11:45:00Z');
    }

    public function test_single_clock_and_repeatable_read_snapshot_survive_a_concurrent_committed_writer(): void
    {
        [$user, $task] = $this->fixture();
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
        $task->update(['deadline_at' => '2026-10-19 08:00:01']);
        config(['database.connections.statistics_writer' => config('database.connections.mysql')]);
        $writer = DB::connection('statistics_writer');
        $this->assertSame('student_planner_testing', $writer->selectOne('SELECT DATABASE() AS name')->name);
        $changed = false;
        DB::listen(function (QueryExecuted $event) use (&$changed, $writer, $task): void {
            if (! $changed && str_contains($event->sql, 'COUNT(*) AS total')) {
                $changed = true;
                $writer->table('tasks')->where('id', $task->id)->update(['status' => 'completed', 'completed_at' => '2026-10-19 08:00:01']);
                $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
            }
        });
        try {
            $result = app(ProgressStatisticsService::class)->calculate($user, '2026-10-19', '2026-10-19');
            $this->assertTrue($changed);
            $this->assertSame('2026-10-19T08:00:00.500000Z', $result['as_of_utc']);
            $this->assertSame(['total' => 1, 'pending' => 1, 'completed' => 0, 'overdue_pending' => 0], $result['tasks']['current']);
            $this->assertSame(0, $result['tasks']['completed_in_period']['total']);
            $this->assertSame(1, app(ProgressStatisticsService::class)->calculate($user, '2026-10-19', '2026-10-19')['tasks']['completed_in_period']['total']);
        } finally {
            DB::purge('statistics_writer');
        }
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $result[$table] = $rows;
        }
        ksort($result);

        return $result;
    }
}
