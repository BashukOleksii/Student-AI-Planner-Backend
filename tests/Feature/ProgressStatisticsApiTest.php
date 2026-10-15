<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Progress\ProgressStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgressStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00.123456Z'));
    }

    private function url(array $parameters = []): string
    {
        return '/api/statistics/progress?'.http_build_query(array_replace([
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ], $parameters));
    }

    public function test_unauthenticated_request_precedes_validation(): void
    {
        $this->getJson('/api/statistics/progress')->assertUnauthorized();
        $this->getJson($this->url())->assertUnauthorized();
        $this->assertSame('/api/statistics/progress', route('statistics.progress', [], false));
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
        $this->getJson($this->url(['start_date' => $start, 'end_date' => $end]))->assertOk()->assertExactJson(['data' => [
            'as_of_utc' => '2026-10-15T12:00:00.123456Z', 'timezone' => 'Europe/Kyiv',
            'period' => ['start_date' => $start, 'end_date' => $end, 'session_attribution' => 'starts_at'],
            'tasks' => ['current' => $zeroCurrent, 'completed_in_period' => $zeroCompleted],
            'subtasks' => ['current' => $zeroCurrent, 'completed_in_period' => $zeroCompleted],
            'study_sessions' => ['started_in_period' => $sessions],
        ]]);
    }

    public static function validRanges(): array
    {
        return [['2026-10-15', '2026-10-15'], ['2026-10-05', '2026-10-11'], ['2026-10-01', '2026-10-31'],
            ['2026-10-27', '2026-11-04'], ['2028-02-01', '2028-02-29'], ['0001-01-01', '0001-01-01'], ['9999-12-31', '9999-12-31']];
    }

    #[DataProvider('invalidRanges')]
    public function test_strict_dates_range_and_unknown_query_fields(array $params, array $errors): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($params))->assertUnprocessable()->assertJsonValidationErrors($errors);
    }

    public static function invalidRanges(): iterable
    {
        foreach (['', ' 2026-10-01', '2026-10-01 ', '2026-2-01', '2026-02-29', '2026-04-31', '2026-13-01',
            '0000-01-01', '2026-10-01T00:00:00Z', '2026-10-01+03:00', '01-10-2026', ['2026-10-01']] as $value) {
            yield [['start_date' => $value], ['start_date']];
        }
        yield [['end_date' => '2026-09-30'], ['end_date']];
        yield [['end_date' => '2026-11-01'], ['end_date']];
        foreach (['user_id', 'timezone', 'status', 'total', 'min_slot_minutes', 'page', 'task_id'] as $field) {
            yield [[$field => '1'], [$field]];
        }
    }

    public function test_both_query_dates_required_and_body_cannot_supply_or_override_them(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->getJson('/api/statistics/progress')->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->getJson('/api/statistics/progress?start_date=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->getJson('/api/statistics/progress?end_date=2026-10-31')->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->json('GET', '/api/statistics/progress', ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->json('GET', $this->url(), ['user_id' => 999, 'timezone' => 'Pacific/Apia', 'start_date' => '2000-01-01', 'total' => 900])
            ->assertOk()->assertJsonPath('data.timezone', 'UTC')->assertJsonPath('data.period.start_date', '2026-10-01')
            ->assertJsonPath('data.tasks.current.total', 0);
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
        $this->actingAs($user, 'web')->getJson($this->url())->assertOk()
            ->assertJsonPath('data.tasks.current', ['total' => 5, 'pending' => 4, 'completed' => 1, 'overdue_pending' => 2])
            ->assertJsonPath('data.tasks.completed_in_period.total', 0)
            ->assertJsonPath('data.subtasks.current', ['total' => 3, 'pending' => 2, 'completed' => 1, 'overdue_pending' => 1])
            ->assertJsonPath('data.subtasks.completed_in_period.without_deadline', 1);
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tasks.current.overdue_pending', 1)
            ->assertJsonPath('data.subtasks.current.overdue_pending', 0);
    }

    public function test_period_completions_use_own_deadlines_exact_equality_and_never_updated_at(): void
    {
        $user = User::factory()->create();
        $parent = Task::factory()->for($user)->create(['deadline_at' => '2026-10-31 12:00:00']);
        foreach (['2026-10-10 12:00:01', '2026-10-10 12:00:00', '2026-10-10 11:59:59', null] as $deadline) {
            $fields = ['status' => 'completed', 'completed_at' => '2026-10-10 12:00:00', 'deadline_at' => $deadline,
                'updated_at' => '2026-11-01 12:00:00'];
            Task::factory()->for($user)->create($fields);
            Subtask::factory()->for($parent)->create($fields);
        }
        Task::factory()->for($user)->create(['updated_at' => '2026-10-10 12:00:00']);
        Subtask::factory()->for($parent)->create(['updated_at' => '2026-10-10 12:00:00']);
        foreach (['2026-09-30 23:59:59', '2026-11-01 00:00:00'] as $completion) {
            Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => $completion]);
            Subtask::factory()->for($parent)->create(['status' => 'completed', 'completed_at' => $completion]);
        }
        $expected = ['total' => 4, 'on_time' => 2, 'late' => 1, 'without_deadline' => 1];
        $this->actingAs($user, 'web')->getJson($this->url())->assertOk()
            ->assertJsonPath('data.tasks.completed_in_period', $expected)->assertJsonPath('data.subtasks.completed_in_period', $expected);
    }

    public function test_soft_deleted_work_is_excluded_but_sessions_remain_valid_owned_history(): void
    {
        $task = Task::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-10 12:00:00']);
        $user = $task->user;
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-10 12:00:00']);
        $active = Task::factory()->for($user)->create();
        $deletedChild = Subtask::factory()->for($active)->create(['status' => 'completed', 'completed_at' => '2026-10-10 12:00:00']);
        $deletedChild->delete();
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'status' => 'completed',
            'completed_at' => '2026-10-05 10:00:00', 'actual_minutes' => 25]);
        $task->delete(); // Deliberately leave an active child under a deleted parent.
        $this->actingAs($user, 'web')->getJson($this->url())->assertOk()->assertJsonPath('data.tasks.current.total', 1)
            ->assertJsonPath('data.tasks.completed_in_period.total', 0)->assertJsonPath('data.subtasks.current.total', 0)
            ->assertJsonPath('data.subtasks.completed_in_period.total', 0)->assertJsonPath('data.study_sessions.started_in_period.completed.count', 1);
        $child->delete();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.study_sessions.started_in_period.completed_actual_minutes', 25);
    }

    public function test_reopened_work_removes_current_snapshot_completion_without_reconstructing_old_events(): void
    {
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        $this->actingAs($task->user, 'web');
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk();
        $base = '/api/tasks/'.$task->id.'/subtasks/'.$child->id;
        $this->postJson($base.'/complete')->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tasks.completed_in_period.total', 1)
            ->assertJsonPath('data.subtasks.completed_in_period.total', 1);
        $this->postJson('/api/tasks/'.$task->id.'/reopen')->assertOk();
        $this->postJson($base.'/reopen')->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tasks.completed_in_period.total', 0)
            ->assertJsonPath('data.subtasks.completed_in_period.total', 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-01T00:00:00Z'));
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk();
        $this->postJson($base.'/complete')->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tasks.current.completed', 1)
            ->assertJsonPath('data.tasks.completed_in_period.total', 0)->assertJsonPath('data.subtasks.completed_in_period.total', 0);
    }

    #[DataProvider('calendarMembership')]
    public function test_exact_local_membership_for_completions_and_session_starts(string $zone, string $date, string $instant, bool $included): void
    {
        $user = User::factory()->create(['timezone' => $zone]);
        $task = Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => $instant]);
        Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => $instant]);
        StudySession::factory()->for($task)->create(['starts_at' => $instant,
            'ends_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $instant, 'UTC')->addSeconds(61)->format('Y-m-d H:i:s')]);
        $this->actingAs($user, 'web')->getJson($this->url(['start_date' => $date, 'end_date' => $date]))->assertOk()
            ->assertJsonPath('data.tasks.completed_in_period.total', (int) $included)
            ->assertJsonPath('data.subtasks.completed_in_period.total', (int) $included)
            ->assertJsonPath('data.study_sessions.started_in_period.planned', ['count' => (int) $included, 'scheduled_seconds' => $included ? 61 : 0]);
    }

    public static function calendarMembership(): array
    {
        return [
            ['UTC', '2026-10-15', '2026-10-14 23:59:59', false],
            ['UTC', '2026-10-15', '2026-10-15 00:00:00', true],
            ['UTC', '2026-10-15', '2026-10-15 23:59:59', true],
            ['UTC', '2026-10-15', '2026-10-16 00:00:00', false],
            ['Europe/Kyiv', '2026-03-29', '2026-03-28 21:59:59', false],
            ['Europe/Kyiv', '2026-03-29', '2026-03-28 22:00:00', true],
            ['Europe/Kyiv', '2026-03-29', '2026-03-29 00:59:59', true],
            ['Europe/Kyiv', '2026-03-29', '2026-03-29 01:00:00', true],
            ['Europe/Kyiv', '2026-03-29', '2026-03-29 20:59:59', true],
            ['Europe/Kyiv', '2026-03-29', '2026-03-29 21:00:00', false],
            ['Europe/Kyiv', '2026-10-25', '2026-10-24 21:00:00', true],
            ['Europe/Kyiv', '2026-10-25', '2026-10-25 00:30:00', true],
            ['Europe/Kyiv', '2026-10-25', '2026-10-25 01:30:00', true],
            ['Europe/Kyiv', '2026-10-25', '2026-10-25 21:59:59', true],
            ['Europe/Kyiv', '2026-10-25', '2026-10-25 22:00:00', false],
            ['America/New_York', '2026-10-15', '2026-10-15 03:59:59', false],
            ['America/New_York', '2026-10-15', '2026-10-15 04:00:00', true],
            ['Pacific/Apia', '2011-12-30', '2011-12-30 10:00:00', false],
            ['America/Juneau', '1867-10-18', '1867-10-18 10:00:00', false],
            ['America/Juneau', '1867-10-18', '1867-10-19 01:00:00', true],
            ['America/Juneau', '1867-10-18', '1867-10-17 12:00:00', true],
        ];
    }

    public function test_all_session_statuses_actual_unknowns_and_cross_midnight_start_attribution(): void
    {
        $task = Task::factory()->create();
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
            StudySession::factory()->for($task)->create(['status' => $status, 'starts_at' => '2026-10-31 23:59:59',
                'ends_at' => '2026-11-01 01:00:00', 'completed_at' => $status === 'completed' ? '2026-11-02 00:00:00' : null]);
        }
        StudySession::factory()->for($task)->create(['status' => 'completed', 'actual_minutes' => 45,
            'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-01 01:00:00', 'completed_at' => '2026-10-03 12:00:00']);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-09-30 23:59:59', 'ends_at' => '2026-10-01 01:00:00']);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-01 01:00:00']);
        $response = $this->actingAs($task->user, 'web')->getJson($this->url())->assertOk();
        foreach (['planned', 'missed', 'rescheduled', 'cancelled'] as $status) {
            $response->assertJsonPath('data.study_sessions.started_in_period.'.$status, ['count' => 1, 'scheduled_seconds' => 3601]);
        }
        $response->assertJsonPath('data.study_sessions.started_in_period.completed', ['count' => 2, 'scheduled_seconds' => 7201])
            ->assertJsonPath('data.study_sessions.started_in_period.completed_actual_minutes', 45)
            ->assertJsonPath('data.study_sessions.started_in_period.completed_with_actual_minutes', 1)
            ->assertJsonPath('data.study_sessions.started_in_period.completed_without_actual_minutes', 1)
            ->assertJsonPath('data.tasks.current.pending', 1)->assertJsonPath('data.tasks.completed_in_period.total', 0);
    }

    public function test_owner_isolation_and_fresh_persisted_timezone_ignore_cached_relations_and_attributes(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $user->load(['tasks', 'studySessions']);
        User::whereKey($user->id)->update(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => '2026-09-30 21:00:00']);
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-09-30 21:00:00']);
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id]);
        $foreign = Task::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-10 00:00:00']);
        Subtask::factory()->for($foreign)->count(4)->create();
        StudySession::factory()->for($foreign)->count(4)->create();
        $result = app(ProgressStatisticsService::class)->calculate($user, '2026-10-01', '2026-10-31');
        $this->assertSame('Europe/Kyiv', $result['timezone']);
        $this->assertSame(1, $result['tasks']['current']['total']);
        $this->assertSame(1, $result['tasks']['completed_in_period']['total']);
        $this->assertSame(1, $result['subtasks']['current']['total']);
        $this->assertSame(1, $result['study_sessions']['started_in_period']['planned']['count']);
        $this->actingAs($user, 'web')->getJson($this->url(['user_id' => $foreign->user_id]))->assertUnprocessable();
    }

    #[DataProvider('elapsedDstDays')]
    public function test_scheduled_duration_uses_real_elapsed_utc_seconds_across_dst(string $date, string $start, string $end, int $seconds): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($user)->create();
        StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end]);
        $this->actingAs($user, 'web')->getJson($this->url(['start_date' => $date, 'end_date' => $date]))->assertOk()
            ->assertJsonPath('data.study_sessions.started_in_period.planned', ['count' => 1, 'scheduled_seconds' => $seconds]);
    }

    public static function elapsedDstDays(): array
    {
        return [['2026-03-29', '2026-03-28 22:00:00', '2026-03-29 21:00:00', 82800],
            ['2026-10-25', '2026-10-24 21:00:00', '2026-10-25 22:00:00', 90000]];
    }

    #[DataProvider('storageEdges')]
    public function test_real_date_periods_intersect_mysql_storage_edges_without_losing_the_last_second(string $date, string $instant): void
    {
        $task = Task::factory()->create(['status' => 'completed', 'completed_at' => $instant]);
        Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => $instant]);
        $this->actingAs($task->user, 'web')->getJson($this->url(['start_date' => $date, 'end_date' => $date]))->assertOk()
            ->assertJsonPath('data.tasks.completed_in_period.total', 1)->assertJsonPath('data.subtasks.completed_in_period.total', 1);
    }

    public static function storageEdges(): array
    {
        return [['1000-01-01', '1000-01-01 00:00:00'], ['9999-12-31', '9999-12-31 23:59:59']];
    }

    public function test_invalid_persisted_timezone_is_a_generic_failure(): void
    {
        $user = User::factory()->create();
        User::whereKey($user->id)->update(['timezone' => 'Invalid/Timezone']);
        $this->actingAs($user, 'web')->getJson($this->url())->assertStatus(500)
            ->assertExactJson(['message' => 'The progress statistics could not be processed.']);
    }

    #[DataProvider('corruptReferences')]
    public function test_corrupt_selected_historical_references_fail_closed_without_debug_details(string $kind): void
    {
        config(['app.debug' => true]);
        $task = Task::factory()->create();
        $other = Task::factory()->for($task->user)->create();
        $foreign = Task::factory()->create();
        $child = Subtask::factory()->for($kind === 'foreign_child' ? $foreign : $other)->create();
        StudySession::factory()->for($kind === 'foreign_task' ? $foreign : $task)->create([
            'user_id' => $task->user_id, 'subtask_id' => $kind === 'foreign_task' ? null : $child->id,
        ]);
        $this->actingAs($task->user, 'web')->getJson($this->url())->assertStatus(500)
            ->assertExactJson(['message' => 'The progress statistics could not be processed.']);
    }

    public static function corruptReferences(): array
    {
        return [['foreign_task'], ['foreign_child'], ['wrong_owned_parent']];
    }

    public function test_corruption_outside_requested_period_does_not_scan_lifetime_session_history(): void
    {
        $owner = User::factory()->create();
        StudySession::factory()->create(['user_id' => $owner->id, 'starts_at' => '2025-01-01 00:00:00', 'ends_at' => '2025-01-01 01:00:00']);
        $this->actingAs($owner, 'web')->getJson($this->url())->assertOk()->assertJsonPath('data.study_sessions.started_in_period.planned.count', 0);
    }

    public function test_read_only_repeatability_all_table_snapshots_and_constant_scoped_query_count(): void
    {
        Bus::fake();
        Notification::fake();
        Http::fake();
        $task = Task::factory()->create();
        $child = Subtask::factory()->for($task)->create();
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id]);
        Reminder::factory()->for($task->user)->create(['task_id' => $task->id, 'trigger_at' => '2026-10-01 00:00:00']);
        $this->actingAs($task->user, 'web');
        $before = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $first = $this->getJson($this->url())->assertOk()->json();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($first, $this->getJson($this->url())->assertOk()->json());
        $this->assertSame($before, $this->snapshot());
        $this->assertCount(8, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('for update', strtolower($query['query']));
        }
        $aggregateQueries = array_filter($queries, fn ($query) => str_contains($query['query'], 'COUNT(*)'));
        $this->assertCount(2, $aggregateQueries);
        $sessionQuery = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'from `study_sessions`')))[0];
        $this->assertStringContainsString('`study_sessions`.`user_id` = ?', $sessionQuery['query']);
        $this->assertStringContainsString('`starts_at` >= ?', $sessionQuery['query']);
        $this->assertStringContainsString('`starts_at` < ?', $sessionQuery['query']);
        $this->assertStringNotContainsString('`ends_at` >', $sessionQuery['query']);
        $this->assertSame([$task->user_id, '2026-10-01 00:00:00', '2026-11-01 00:00:00'], $sessionQuery['bindings']);
        Task::factory()->count(20)->for($task->user)->create();
        Subtask::factory()->count(40)->for($task)->create();
        StudySession::factory()->count(40)->for($task)->create(['subtask_id' => $child->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tasks.current.total', 21)
            ->assertJsonPath('data.study_sessions.started_in_period.planned.count', 41);
        $this->assertCount(count($queries), DB::getQueryLog());
        DB::disableQueryLog();
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing() as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $snapshot[$table] = $rows;
        }
        ksort($snapshot);

        return $snapshot;
    }
}
