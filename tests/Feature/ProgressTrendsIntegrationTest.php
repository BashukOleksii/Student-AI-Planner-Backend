<?php

namespace Tests\Feature;

use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Progress\ProgressTrendsService;
use App\Services\Reminders\ReminderDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProgressTrendsIntegrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T08:00:00.123456Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function emptyMetrics(): array
    {
        $zero = ['total' => 0, 'on_time' => 0, 'late' => 0, 'without_deadline' => 0];
        $sessions = [];
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
            $sessions[$status] = ['count' => 0, 'scheduled_seconds' => 0];
        }
        $sessions += ['completed_actual_minutes' => 0, 'completed_with_actual_minutes' => 0, 'completed_without_actual_minutes' => 0];

        return ['tasks_completed' => $zero, 'subtasks_completed' => $zero, 'study_sessions' => $sessions];
    }

    public function test_actual_task_child_completion_session_completion_and_rescheduled_successor_reconcile_every_bucket_and_g1(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create(['estimated_minutes' => 240, 'deadline_at' => '2026-10-18 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 240, 'deadline_at' => '2026-10-17 12:00:00']);
        foreach (range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);
        }
        $original = StudySession::factory()->for($task)->create(['subtask_id' => $child->id,
            'starts_at' => '2026-10-15 09:00:00', 'ends_at' => '2026-10-15 10:00:00']);
        $completed = StudySession::factory()->for($task)->create(['subtask_id' => $child->id,
            'starts_at' => '2026-10-15 10:00:00', 'ends_at' => '2026-10-15 11:00:00']);
        $this->actingAs($user, 'web');
        $sourceReminder = $this->postJson('/api/reminders', ['study_session_id' => $original->id, 'trigger_at' => '2026-10-15T08:45:00Z'])
            ->assertCreated()->json('data.id');
        $sent = $this->postJson('/api/reminders', ['message' => 'Persist inbox state', 'trigger_at' => '2026-10-14T08:00:01Z'])
            ->assertCreated()->json('data.id');
        $this->travelTo(CarbonImmutable::parse('2026-10-14T08:00:01.500000Z'));
        $this->assertSame(['status' => 'sent'], app(ReminderDeliveryService::class)->process($user->id, $sent));
        $this->postJson('/api/study-sessions/'.$original->id.'/reschedule', ['starts_at' => '2026-10-16T09:00:00Z', 'ends_at' => '2026-10-16T10:00:00Z'])
            ->assertCreated();
        $this->getJson('/api/reminders/'.$sourceReminder)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00.123456Z'));
        $this->postJson('/api/study-sessions/'.$completed->id.'/complete', ['actual_minutes' => 25])->assertOk();
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk();
        $this->postJson('/api/tasks/'.$task->id.'/subtasks/'.$child->id.'/complete')->assertOk();
        $first = $this->emptyMetrics();
        $first['tasks_completed'] = $first['subtasks_completed'] = ['total' => 1, 'on_time' => 1, 'late' => 0, 'without_deadline' => 0];
        $first['study_sessions']['completed'] = $first['study_sessions']['rescheduled'] = ['count' => 1, 'scheduled_seconds' => 3600];
        $first['study_sessions']['completed_actual_minutes'] = 25;
        $first['study_sessions']['completed_with_actual_minutes'] = 1;
        $second = $this->emptyMetrics();
        $second['study_sessions']['planned'] = ['count' => 1, 'scheduled_seconds' => 3600];
        $total = $first;
        $total['study_sessions']['planned'] = $second['study_sessions']['planned'];
        $snapshot = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $url = '/api/statistics/trends?start_date=2026-10-15&end_date=2026-10-17';
        $data = $this->getJson($url)->assertOk()->assertExactJson(['data' => [
            'as_of_utc' => '2026-10-15T12:00:00.123456Z', 'timezone' => 'UTC',
            'period' => ['start_date' => '2026-10-15', 'end_date' => '2026-10-17', 'aggregation' => 'day', 'session_attribution' => 'starts_at'],
            'days' => [['date' => '2026-10-15', ...$first], ['date' => '2026-10-16', ...$second], ['date' => '2026-10-17', ...$this->emptyMetrics()]],
            'totals' => $total,
        ]])->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(6, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertSame($data, $this->getJson($url)->assertOk()->json('data'));
        $g1 = $this->getJson('/api/statistics/progress?start_date=2026-10-15&end_date=2026-10-17')->assertOk()->json('data');
        $this->assertSame($g1['tasks']['completed_in_period'], $total['tasks_completed']);
        $this->assertSame($g1['subtasks']['completed_in_period'], $total['subtasks_completed']);
        $this->assertSame($g1['study_sessions']['started_in_period'], $total['study_sessions']);
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertNull($user->notifications()->sole()->read_at);
    }

    public function test_one_clock_and_repeatable_read_survive_an_independent_committed_writer_mid_report(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00.123456Z'));
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => '2026-10-15 09:00:00']);
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-15 09:00:00']);
        $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'status' => 'completed',
            'starts_at' => '2026-10-15 09:00:00', 'ends_at' => '2026-10-15 10:00:00',
            'completed_at' => '2026-10-15 10:00:00', 'actual_minutes' => 25]);
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
        config(['database.connections.trends_writer' => config('database.connections.mysql')]);
        $writer = DB::connection('trends_writer');
        $this->assertSame('student_planner_testing', $writer->selectOne('SELECT DATABASE() AS name')->name);
        $changed = false;
        DB::listen(function (QueryExecuted $event) use (&$changed, $writer, $task, $child, $session): void {
            if (! $changed && str_contains($event->sql, 'from `tasks`') && str_contains($event->sql, '`completed_at`')) {
                $changed = true;
                $writer->transaction(function () use ($writer, $task, $child, $session): void {
                    $writer->table('tasks')->where('id', $task->id)->update(['status' => 'pending', 'completed_at' => null]);
                    $writer->table('subtasks')->where('id', $child->id)->update(['status' => 'pending', 'completed_at' => null]);
                    $writer->table('study_sessions')->where('id', $session->id)->update(['status' => 'missed', 'completed_at' => null, 'actual_minutes' => null]);
                });
                $this->travelTo(CarbonImmutable::parse('2026-10-15T18:00:00Z'));
            }
        });
        try {
            $data = app(ProgressTrendsService::class)->calculate($user, '2026-10-15', '2026-10-15');
            $this->assertTrue($changed);
            $this->assertSame('2026-10-15T12:00:00.123456Z', $data['as_of_utc']);
            $this->assertSame(1, $data['totals']['tasks_completed']['total']);
            $this->assertSame(1, $data['totals']['subtasks_completed']['total']);
            $this->assertSame(1, $data['totals']['study_sessions']['completed']['count']);
            $this->assertSame(25, $data['totals']['study_sessions']['completed_actual_minutes']);
            $next = app(ProgressTrendsService::class)->calculate($user, '2026-10-15', '2026-10-15');
            $this->assertSame(0, $next['totals']['tasks_completed']['total']);
            $this->assertSame(0, $next['totals']['subtasks_completed']['total']);
            $this->assertSame(0, $next['totals']['study_sessions']['completed']['count']);
            $this->assertSame(1, $next['totals']['study_sessions']['missed']['count']);
        } finally {
            DB::purge('trends_writer');
        }
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $snapshot[$table] = $rows;
        }
        ksort($snapshot);

        return $snapshot;
    }
}
