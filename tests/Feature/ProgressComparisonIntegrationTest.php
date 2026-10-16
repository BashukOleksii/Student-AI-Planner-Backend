<?php

namespace Tests\Feature;

use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Progress\ProgressComparisonService;
use App\Services\Reminders\ReminderDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsProgressComparison;
use Tests\TestCase;

class ProgressComparisonIntegrationTest extends TestCase
{
    use AssertsProgressComparison, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T08:00:00.123456Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_real_lifecycle_fixture_rescheduling_known_actuals_and_reminder_inbox_state_reconcile(): void
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
        $baseline = $this->emptyMetrics();
        $baseline['tasks_completed'] = $baseline['subtasks_completed'] = ['total' => 1, 'on_time' => 1, 'late' => 0, 'without_deadline' => 0];
        $baseline['study_sessions']['completed'] = $baseline['study_sessions']['rescheduled'] = ['count' => 1, 'scheduled_seconds' => 3600];
        $baseline['study_sessions']['completed_actual_minutes'] = 25;
        $baseline['study_sessions']['completed_with_actual_minutes'] = 1;
        $current = $this->emptyMetrics();
        $current['study_sessions']['planned'] = ['count' => 1, 'scheduled_seconds' => 3600];
        Bus::fake();
        Notification::fake();
        Http::fake();
        $snapshot = $this->snapshot();
        $url = $this->url(['baseline_start_date' => '2026-10-15', 'baseline_end_date' => '2026-10-15',
            'current_start_date' => '2026-10-16', 'current_end_date' => '2026-10-16']);
        $data = $this->getJson($url)->assertOk()->json('data');
        $this->assertSame($baseline, $data['baseline']['totals']);
        $this->assertSame($current, $data['current']['totals']);
        $this->assertSame(-25, $data['delta']['study_sessions']['completed_actual_minutes']);
        $this->assertSame('decreased', $data['observations']['known_actual_minutes']);
        $this->assertSame($data, $this->getJson($url)->assertOk()->json('data'));
        $this->reconcile($data);
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertNull($user->notifications()->sole()->read_at);
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    #[DataProvider('commitPoints')]
    public function test_both_periods_share_one_repeatable_read_snapshot_and_clock_during_independent_commit(bool $afterOwner): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00.123456Z'));
        $user = User::factory()->create();
        $records = [];
        foreach (['2026-09-01', '2026-10-01'] as $date) {
            $task = Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => $date.' 09:00:00']);
            $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => $date.' 09:00:00']);
            $session = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'status' => 'completed',
                'starts_at' => $date.' 09:00:00', 'ends_at' => $date.' 10:00:00', 'completed_at' => $date.' 10:00:00', 'actual_minutes' => 25]);
            $records[] = [$task, $child, $session];
        }
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
        config(['database.connections.comparison_writer' => config('database.connections.mysql')]);
        $writer = DB::connection('comparison_writer');
        $this->assertSame('student_planner_testing', $writer->selectOne('SELECT DATABASE() AS name')->name);
        $this->assertNotSame(DB::connection()->getPdo(), $writer->getPdo());
        $changed = false;
        DB::listen(function (QueryExecuted $event) use (&$changed, $writer, $records, $user, $afterOwner): void {
            // Exercise commits both before activity loading and between source reads.
            $trigger = $afterOwner
                ? str_contains($event->sql, 'from `users`') && str_contains($event->sql, '`timezone`')
                : str_contains($event->sql, 'from `tasks`') && str_contains($event->sql, '`completed_at`');
            if (! $changed && $trigger) {
                $changed = true;
                $writer->transaction(function () use ($writer, $records, $user): void {
                    foreach ($records as [$task, $child, $session]) {
                        $writer->table('tasks')->where('id', $task->id)->update(['status' => 'pending', 'completed_at' => null]);
                        $writer->table('subtasks')->where('id', $child->id)->update(['status' => 'pending', 'completed_at' => null]);
                        $writer->table('study_sessions')->where('id', $session->id)->update(['status' => 'missed', 'completed_at' => null, 'actual_minutes' => null]);
                    }
                    $writer->table('users')->where('id', $user->id)->update(['timezone' => 'Europe/Kyiv']);
                });
            }
            if ($changed && str_contains($event->sql, 'from `tasks`') && str_contains($event->sql, '`completed_at`')) {
                $this->travelTo(CarbonImmutable::parse('2026-10-15T18:00:00Z'));
            }
        });
        try {
            $data = app(ProgressComparisonService::class)->calculate($user, ...array_values($this->dates()));
            $this->assertTrue($changed);
            $this->assertSame('2026-10-15T12:00:00.123456Z', $data['as_of_utc']);
            $this->assertSame('UTC', $data['timezone']);
            foreach (['baseline', 'current'] as $side) {
                $this->assertSame(1, $data[$side]['totals']['tasks_completed']['total']);
                $this->assertSame(1, $data[$side]['totals']['subtasks_completed']['total']);
                $this->assertSame(1, $data[$side]['totals']['study_sessions']['completed']['count']);
                $this->assertSame(25, $data[$side]['totals']['study_sessions']['completed_actual_minutes']);
                $this->assertSame(0, $data[$side]['totals']['study_sessions']['missed']['count']);
            }
            $this->assertSame($this->emptyMetrics(), $data['delta']);
            $this->actingAs($user, 'web');
            $next = $this->getJson($this->url())->assertOk()->json('data');
            $this->assertSame('Europe/Kyiv', $next['timezone']);
            $this->assertSame('2026-10-15T18:00:00.000000Z', $next['as_of_utc']);
            foreach (['baseline', 'current'] as $side) {
                $this->assertSame(0, $next[$side]['totals']['tasks_completed']['total']);
                $this->assertSame(0, $next[$side]['totals']['subtasks_completed']['total']);
                $this->assertSame(0, $next[$side]['totals']['study_sessions']['completed']['count']);
                $this->assertSame(1, $next[$side]['totals']['study_sessions']['missed']['count']);
            }
            $this->reconcile($next);
        } finally {
            DB::purge('comparison_writer');
        }
    }

    public static function commitPoints(): array
    {
        return [[true], [false]];
    }
}
