<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactProgressTrendsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00.123456Z'));
    }

    private function url(string $start = '2026-10-01', string $end = '2026-10-31', array $parameters = []): string
    {
        return '/api/statistics/trends?'.http_build_query(array_replace(['start_date' => $start, 'end_date' => $end], $parameters));
    }

    private function emptyMetrics(): array
    {
        $completion = ['total' => 0, 'on_time' => 0, 'late' => 0, 'without_deadline' => 0];
        $sessions = [];
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
            $sessions[$status] = ['count' => 0, 'scheduled_seconds' => 0];
        }
        $sessions += ['completed_actual_minutes' => 0, 'completed_with_actual_minutes' => 0, 'completed_without_actual_minutes' => 0];

        return ['tasks_completed' => $completion, 'subtasks_completed' => $completion, 'study_sessions' => $sessions];
    }

    private function reconcile(array $trends, string $start = '2026-10-01', string $end = '2026-10-31'): void
    {
        $g1 = $this->getJson('/api/statistics/progress?'.http_build_query(['start_date' => $start, 'end_date' => $end]))->assertOk()->json('data');
        $this->assertSame($g1['as_of_utc'], $trends['as_of_utc']);
        $this->assertSame($g1['timezone'], $trends['timezone']);
        $this->assertSame($g1['tasks']['completed_in_period'], $trends['totals']['tasks_completed']);
        $this->assertSame($g1['subtasks']['completed_in_period'], $trends['totals']['subtasks_completed']);
        $this->assertSame($g1['study_sessions']['started_in_period'], $trends['totals']['study_sessions']);
    }

    #[DataProvider('validRanges')]
    public function test_zero_filled_contract_for_one_day_week_month_custom_and_storage_edge_labels(string $start, string $end, int $count): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Europe/Kyiv']), 'web');
        $days = [];
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC');
        for ($i = 0; $i < $count; $i++) {
            $days[] = ['date' => $date->addDays($i)->format('Y-m-d'), ...$this->emptyMetrics()];
        }
        $data = $this->getJson($this->url($start, $end))->assertOk()->assertExactJson(['data' => ['as_of_utc' => '2026-10-15T12:00:00.123456Z', 'timezone' => 'Europe/Kyiv', 'period' => ['start_date' => $start, 'end_date' => $end, 'aggregation' => 'day', 'session_attribution' => 'starts_at'], 'days' => $days, 'totals' => $this->emptyMetrics()]])->json('data');
        $this->reconcile($data, $start, $end);
    }

    public static function validRanges(): array
    {
        return [0 => ['2026-10-15', '2026-10-15', 1]];
    }

    #[DataProvider('invalidRanges')]
    public function test_exact_g1_query_validation_and_unknown_filter_rejection(array $parameters, array $fields): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url(parameters: $parameters))->assertUnprocessable()->assertJsonValidationErrors($fields);
    }

    public static function invalidRanges(): array
    {
        return [0 => [['aggregation' => 'day'], [0 => 'aggregation']]];
    }

    #[DataProvider('calendarCases')]
    public function test_mysql_attribution_matches_exact_daily_timezone_membership_and_g1(string $zone, string $start, string $end, array $instants, array $counts): void
    {
        $user = User::factory()->create(['timezone' => $zone]);
        $parent = Task::factory()->for($user)->create();
        foreach ($instants as $instant) {
            Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => $instant]);
            Subtask::factory()->for($parent)->create(['status' => 'completed', 'completed_at' => $instant]);
            StudySession::factory()->for($parent)->create(['starts_at' => $instant, 'ends_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $instant, 'UTC')->addSeconds(61)->format('Y-m-d H:i:s')]);
        }
        $data = $this->actingAs($user, 'web')->getJson($this->url($start, $end))->assertOk()->json('data');
        $this->assertSame($counts, array_column(array_column($data['days'], 'tasks_completed'), 'total'));
        $this->assertSame($counts, array_column(array_column($data['days'], 'subtasks_completed'), 'total'));
        foreach ($data['days'] as $index => $day) {
            $this->assertSame(['count' => $counts[$index], 'scheduled_seconds' => $counts[$index] * 61], $day['study_sessions']['planned']);
        }
        $this->reconcile($data, $start, $end);
    }

    public static function calendarCases(): array
    {
        return [0 => ['Europe/Kyiv', '2026-03-28', '2026-03-30', [0 => '2026-03-28 21:59:59', 1 => '2026-03-28 22:00:00', 2 => '2026-03-29 00:59:59', 3 => '2026-03-29 01:00:00', 4 => '2026-03-29 20:59:59', 5 => '2026-03-29 21:00:00'], [0 => 1, 1 => 4, 2 => 1]]];
    }

    #[DataProvider('elapsedDstDays')]
    public function test_full_scheduled_duration_on_short_and_long_dst_days(string $date, string $start, string $end, int $seconds): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($user)->create();
        StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end]);
        $data = $this->actingAs($user, 'web')->getJson($this->url($date, $date))->assertOk()->assertJsonPath('data.days.0.study_sessions.planned', ['count' => 1, 'scheduled_seconds' => $seconds])->json('data');
        $this->reconcile($data, $date, $date);
    }

    public static function elapsedDstDays(): array
    {
        return [0 => ['2026-03-29', '2026-03-28 22:00:00', '2026-03-29 21:00:00', 82800]];
    }

    public function test_lateness_uses_exact_own_deadlines_and_current_state_after_edits_reopening_and_deletion(): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-16 12:00:00']);
        $child = Subtask::factory()->for($task)->create();
        foreach (['2026-10-15 12:00:01', '2026-10-15 12:00:00', '2026-10-15 11:59:59', null] as $deadline) {
            Task::factory()->for($task->user)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00', 'deadline_at' => $deadline, 'updated_at' => '2026-11-01 00:00:00']);
            Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00', 'deadline_at' => $deadline]);
        }
        $this->actingAs($task->user, 'web');
        $expected = ['total' => 4, 'on_time' => 2, 'late' => 1, 'without_deadline' => 1];
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed', $expected)->assertJsonPath('data.days.14.subtasks_completed', $expected)->json('data');
        $this->reconcile($data);
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk();
        $base = '/api/tasks/'.$task->id.'/subtasks/'.$child->id;
        $this->postJson($base.'/complete')->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed.on_time', 3)->assertJsonPath('data.days.14.subtasks_completed.without_deadline', 2);
        Subtask::where('task_id', $task->id)->update(['deadline_at' => null]);
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-15T12:00:00Z'])->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed.on_time', 3);
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-15T11:59:59Z'])->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed.late', 2);
        $this->postJson('/api/tasks/'.$task->id.'/reopen')->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed.total', 4)->assertJsonPath('data.days.14.subtasks_completed.total', 5);
        $this->postJson($base.'/reopen')->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.subtasks_completed.total', 4);
        $task->delete();
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.subtasks_completed.total', 0)->json('data');
        $this->reconcile($data);
    }

    public function test_soft_deleted_work_is_excluded_and_sessions_retain_deleted_parent_child_history(): void
    {
        $task = Task::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'status' => 'completed', 'completed_at' => '2026-10-15 12:00:00', 'actual_minutes' => 25]);
        $active = Task::factory()->for($task->user)->create();
        $deleted = Subtask::factory()->for($active)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
        $deleted->delete();
        $task->delete();
        $this->actingAs($task->user, 'web');
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('data.totals.tasks_completed.total', 0)->assertJsonPath('data.totals.subtasks_completed.total', 0)->assertJsonPath('data.days.4.study_sessions.completed_actual_minutes', 25)->json('data');
        $this->reconcile($data);
        $child->delete();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.totals.study_sessions.completed_actual_minutes', 25);
    }
}
