<?php

namespace Tests\Feature;

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
class CompactProgressStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00.123456Z'));
    }

    private function url(array $parameters = []): string
    {
        return '/api/statistics/progress?'.http_build_query(array_replace(['start_date' => '2026-10-01', 'end_date' => '2026-10-31'], $parameters));
    }

    #[DataProvider('validRanges')]
    public function test_valid_local_periods_and_stable_empty_contract(string $start, string $end): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Europe/Kyiv']), 'web');
        $zeroCurrent = ['total' => 0, 'pending' => 0, 'completed' => 0, 'overdue_pending' => 0];
        $zeroCompleted = ['total' => 0, 'on_time' => 0, 'late' => 0, 'without_deadline' => 0];
        $sessions = [];
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
            $sessions[$status] = ['count' => 0, 'scheduled_seconds' => 0];
        }
        $sessions += ['completed_actual_minutes' => 0, 'completed_with_actual_minutes' => 0, 'completed_without_actual_minutes' => 0];
        $this->getJson($this->url(['start_date' => $start, 'end_date' => $end]))->assertOk()->assertExactJson(['data' => ['as_of_utc' => '2026-10-15T12:00:00.123456Z', 'timezone' => 'Europe/Kyiv', 'period' => ['start_date' => $start, 'end_date' => $end, 'session_attribution' => 'starts_at'], 'tasks' => ['current' => $zeroCurrent + ['missed' => 0, 'overdue_missed' => 0, 'completion_percent' => null], 'completed_in_period' => $zeroCompleted], 'subtasks' => ['current' => $zeroCurrent, 'completed_in_period' => $zeroCompleted], 'study_sessions' => ['started_in_period' => $sessions], 'subject_workload_in_period' => []]]);
    }

    public static function validRanges(): array
    {
        return [0 => ['2026-10-15', '2026-10-15']];
    }

    #[DataProvider('invalidRanges')]
    public function test_strict_dates_range_and_unknown_query_fields(array $params, array $errors): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($params))->assertUnprocessable()->assertJsonValidationErrors($errors);
    }

    public static function invalidRanges(): array
    {
        return [0 => [['start_date' => ''], [0 => 'start_date']]];
    }

    public function test_both_query_dates_required_and_body_cannot_supply_or_override_them(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->getJson('/api/statistics/progress')->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->getJson('/api/statistics/progress?start_date=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->getJson('/api/statistics/progress?end_date=2026-10-31')->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->json('GET', '/api/statistics/progress', ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'])->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->json('GET', $this->url(), ['user_id' => 999, 'timezone' => 'Pacific/Apia', 'start_date' => '2000-01-01', 'total' => 900])->assertOk()->assertJsonPath('data.timezone', 'UTC')->assertJsonPath('data.period.start_date', '2026-10-01')->assertJsonPath('data.tasks.current.total', 0);
    }

    public function test_current_counts_are_lifetime_and_parent_child_status_and_deadlines_are_independent(): void
    {
        $user = User::factory()->create();
        $pending = Task::factory()->for($user)->create(['deadline_at' => '2026-10-15 11:59:59']);
        Task::factory()->for($user)->create(['deadline_at' => '2026-10-15 12:00:00']);
        Task::factory()->for($user)->create(['deadline_at' => '2026-10-15 12:00:01']);
        Task::factory()->for($user)->create(['deadline_at' => null]);
        $completed = Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => '2025-01-01 00:00:00', 'deadline_at' => '2024-01-01 00:00:00']);
        Subtask::factory()->for($completed)->create(['deadline_at' => null]);
        Subtask::factory()->for($completed)->create(['deadline_at' => '2026-10-15 12:00:00']);
        Subtask::factory()->for($pending)->create(['status' => 'completed', 'completed_at' => '2026-10-10 00:00:00']);
        $this->actingAs($user, 'web')->getJson($this->url())->assertOk()->assertJsonPath('data.tasks.current', ['total' => 5, 'pending' => 4, 'completed' => 1, 'overdue_pending' => 2, 'missed' => 0, 'overdue_missed' => 0, 'completion_percent' => 20])->assertJsonPath('data.tasks.completed_in_period.total', 0)->assertJsonPath('data.subtasks.current', ['total' => 3, 'pending' => 2, 'completed' => 1, 'overdue_pending' => 1])->assertJsonPath('data.subtasks.completed_in_period.without_deadline', 1);
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tasks.current.overdue_pending', 1)->assertJsonPath('data.subtasks.current.overdue_pending', 0);
    }

    public function test_missed_current_counts_are_separate_owned_active_and_do_not_change_completion_denominator(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $user = User::factory()->create();
        $pending = Task::factory()->for($user)->create(['deadline_at' => '2026-10-15 11:59:59']);
        foreach (['2026-10-15 11:59:59', '2026-10-15 12:00:00', '2026-12-01 00:00:00', null] as $deadline) {
            $missed = Task::factory()->for($user)->create(['status' => 'missed', 'deadline_at' => $deadline]);
        }
        Subtask::factory()->for($missed)->create();
        Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => '2025-01-01 00:00:00']);
        Task::factory()->create(['status' => 'missed', 'deadline_at' => '2020-01-01 00:00:00']);
        Task::factory()->for($user)->create(['status' => 'missed'])->delete();
        $this->actingAs($user, 'web')->getJson($this->url())->assertOk()->assertJsonPath('data.tasks.current', ['total' => 6, 'pending' => 1, 'completed' => 1, 'overdue_pending' => 1, 'missed' => 4, 'overdue_missed' => 1, 'completion_percent' => 16.7])->assertJsonPath('data.subtasks.current', ['total' => 1, 'pending' => 1, 'completed' => 0, 'overdue_pending' => 0]);
        $this->postJson('/api/tasks/'.$pending->id.'/miss')->assertOk();
        $this->getJson($this->url(['start_date' => '2020-01-01', 'end_date' => '2020-01-01']))->assertOk()->assertJsonPath('data.tasks.current', ['total' => 6, 'pending' => 0, 'completed' => 1, 'overdue_pending' => 0, 'missed' => 5, 'overdue_missed' => 2, 'completion_percent' => 16.7])->assertJsonPath('data.tasks.completed_in_period.total', 0);
    }

    #[DataProvider('completionPercentages')]
    public function test_completion_percentage_uses_only_current_owned_active_tasks_across_all_dates(int $completed, float $percent): void
    {
        $user = User::factory()->create();
        $tasks = Task::factory()->for($user)->count(3)->create();
        foreach ($tasks->take($completed) as $task) {
            $task->update(['status' => 'completed', 'completed_at' => '2025-01-01 00:00:00']);
        }
        Subtask::factory()->for($tasks->first())->count(5)->create(['status' => 'completed', 'completed_at' => '2026-10-10 00:00:00']);
        Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => '2026-10-10 00:00:00'])->delete();
        Task::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-10 00:00:00']);
        $this->actingAs($user, 'web');
        foreach ([[], ['start_date' => '2025-01-01', 'end_date' => '2025-01-01']] as $range) {
            $response = $this->getJson($this->url($range))->assertOk()->assertJsonPath('data.tasks.current.total', 3)->assertJsonPath('data.tasks.current.completed', $completed);
            $this->assertSame($percent, (float) $response->json('data.tasks.current.completion_percent'));
        }
    }

    public static function completionPercentages(): array
    {
        return [0 => [0, 0.0]];
    }
}
