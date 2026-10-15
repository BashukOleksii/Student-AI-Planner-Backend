<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Progress\ProgressTrendsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\ProgressTrendsServiceTest;

class ProgressTrendsApiTest extends TestCase
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

    public function test_authentication_precedes_validation_and_route_has_no_write_methods(): void
    {
        $this->getJson('/api/statistics/trends')->assertUnauthorized();
        $this->getJson($this->url())->assertUnauthorized();
        $this->assertSame('/api/statistics/trends', route('statistics.trends', [], false));
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/statistics/trends')->assertStatus(405);
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
        $data = $this->getJson($this->url($start, $end))->assertOk()->assertExactJson(['data' => [
            'as_of_utc' => '2026-10-15T12:00:00.123456Z', 'timezone' => 'Europe/Kyiv',
            'period' => ['start_date' => $start, 'end_date' => $end, 'aggregation' => 'day', 'session_attribution' => 'starts_at'],
            'days' => $days, 'totals' => $this->emptyMetrics(),
        ]])->json('data');
        $this->reconcile($data, $start, $end);
    }

    public static function validRanges(): array
    {
        return [['2026-10-15', '2026-10-15', 1], ['2026-10-05', '2026-10-11', 7], ['2026-10-01', '2026-10-31', 31],
            ['2026-10-30', '2026-11-02', 4], ['2028-02-01', '2028-02-29', 29], ['0001-01-01', '0001-01-01', 1],
            ['9999-12-31', '9999-12-31', 1]];
    }

    #[DataProvider('invalidRanges')]
    public function test_exact_g1_query_validation_and_unknown_filter_rejection(array $parameters, array $fields): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url(parameters: $parameters))->assertUnprocessable()->assertJsonValidationErrors($fields);
    }

    public static function invalidRanges(): iterable
    {
        yield from ProgressStatisticsApiTest::invalidRanges();
        yield [['aggregation' => 'day'], ['aggregation']];
        yield [['end_date' => '2026-10-31 '], ['end_date']];
    }

    public function test_dates_must_come_from_query_and_body_cannot_override_owner_timezone_or_totals(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/statistics/trends')->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->getJson('/api/statistics/trends?start_date=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->getJson('/api/statistics/trends?end_date=2026-10-31')->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->json('GET', '/api/statistics/trends', ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->json('GET', $this->url('2026-10-15', '2026-10-15'), ['start_date' => '2000-01-01', 'user_id' => 999,
            'timezone' => 'Pacific/Apia', 'totals' => 999])->assertOk()->assertJsonPath('data.period.start_date', '2026-10-15')
            ->assertJsonPath('data.timezone', 'UTC')->assertJsonPath('data.totals', $this->emptyMetrics());
    }

    #[DataProvider('calendarCases')]
    public function test_mysql_attribution_matches_exact_daily_timezone_membership_and_g1(string $zone, string $start, string $end, array $instants, array $counts): void
    {
        $user = User::factory()->create(['timezone' => $zone]);
        $parent = Task::factory()->for($user)->create();
        foreach ($instants as $instant) {
            Task::factory()->for($user)->create(['status' => 'completed', 'completed_at' => $instant]);
            Subtask::factory()->for($parent)->create(['status' => 'completed', 'completed_at' => $instant]);
            StudySession::factory()->for($parent)->create(['starts_at' => $instant,
                'ends_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $instant, 'UTC')->addSeconds(61)->format('Y-m-d H:i:s')]);
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
        return ProgressTrendsServiceTest::calendarCases();
    }

    #[DataProvider('elapsedDstDays')]
    public function test_full_scheduled_duration_on_short_and_long_dst_days(string $date, string $start, string $end, int $seconds): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($user)->create();
        StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end]);
        $data = $this->actingAs($user, 'web')->getJson($this->url($date, $date))->assertOk()
            ->assertJsonPath('data.days.0.study_sessions.planned', ['count' => 1, 'scheduled_seconds' => $seconds])->json('data');
        $this->reconcile($data, $date, $date);
    }

    public static function elapsedDstDays(): array
    {
        return ProgressStatisticsApiTest::elapsedDstDays();
    }

    public function test_lateness_uses_exact_own_deadlines_and_current_state_after_edits_reopening_and_deletion(): void
    {
        $task = Task::factory()->create(['deadline_at' => '2026-10-16 12:00:00']);
        $child = Subtask::factory()->for($task)->create();
        foreach (['2026-10-15 12:00:01', '2026-10-15 12:00:00', '2026-10-15 11:59:59', null] as $deadline) {
            Task::factory()->for($task->user)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00',
                'deadline_at' => $deadline, 'updated_at' => '2026-11-01 00:00:00']);
            Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00', 'deadline_at' => $deadline]);
        }
        $this->actingAs($task->user, 'web');
        $expected = ['total' => 4, 'on_time' => 2, 'late' => 1, 'without_deadline' => 1];
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed', $expected)
            ->assertJsonPath('data.days.14.subtasks_completed', $expected)->json('data');
        $this->reconcile($data);
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk();
        $base = '/api/tasks/'.$task->id.'/subtasks/'.$child->id;
        $this->postJson($base.'/complete')->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed.on_time', 3)
            ->assertJsonPath('data.days.14.subtasks_completed.without_deadline', 2);
        // Clear the child's 12:00:01 bound before narrowing the parent deadline.
        Subtask::where('task_id', $task->id)->update(['deadline_at' => null]);
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-15T12:00:00Z'])->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed.on_time', 3);
        $this->patchJson('/api/tasks/'.$task->id, ['deadline_at' => '2026-10-15T11:59:59Z'])->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed.late', 2);
        $this->postJson('/api/tasks/'.$task->id.'/reopen')->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.days.14.tasks_completed.total', 4)
            ->assertJsonPath('data.days.14.subtasks_completed.total', 5);
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
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'status' => 'completed',
            'completed_at' => '2026-10-15 12:00:00', 'actual_minutes' => 25]);
        $active = Task::factory()->for($task->user)->create();
        $deleted = Subtask::factory()->for($active)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
        $deleted->delete();
        $task->delete(); // An active child under a deleted parent is excluded too.
        $this->actingAs($task->user, 'web');
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('data.totals.tasks_completed.total', 0)
            ->assertJsonPath('data.totals.subtasks_completed.total', 0)->assertJsonPath('data.days.4.study_sessions.completed_actual_minutes', 25)->json('data');
        $this->reconcile($data);
        $child->delete();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.totals.study_sessions.completed_actual_minutes', 25);
    }

    public function test_all_session_statuses_and_midnight_boundaries_preserve_unknown_and_different_actual_time(): void
    {
        $task = Task::factory()->create();
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
            StudySession::factory()->for($task)->create(['status' => $status, 'starts_at' => '2026-10-15 23:59:59',
                'ends_at' => '2026-10-17 01:00:00', 'completed_at' => $status === 'completed' ? '2026-10-20 00:00:00' : null]);
        }
        StudySession::factory()->for($task)->create(['starts_at' => '2026-10-16 00:00:00', 'ends_at' => '2026-10-16 01:00:00',
            'status' => 'completed', 'completed_at' => '2026-10-17 12:00:00', 'actual_minutes' => 25]);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-10-14 23:59:59', 'ends_at' => '2026-10-15 00:01:00']);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-10-17 00:00:00', 'ends_at' => '2026-10-17 00:01:00']);
        $data = $this->actingAs($task->user, 'web')->getJson($this->url('2026-10-15', '2026-10-16'))->assertOk()->json('data');
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
            $this->assertSame(['count' => 1, 'scheduled_seconds' => 90001], $data['days'][0]['study_sessions'][$status]);
        }
        $this->assertSame(1, $data['days'][0]['study_sessions']['completed_without_actual_minutes']);
        $this->assertSame(0, $data['days'][0]['study_sessions']['completed_actual_minutes']);
        $this->assertSame(['count' => 1, 'scheduled_seconds' => 3600], $data['days'][1]['study_sessions']['completed']);
        $this->assertSame(25, $data['days'][1]['study_sessions']['completed_actual_minutes']);
        $this->assertSame(1, $data['days'][1]['study_sessions']['completed_with_actual_minutes']);
        $this->reconcile($data, '2026-10-15', '2026-10-16');
    }

    public function test_owner_scopes_and_fresh_timezone_ignore_cached_models_and_foreign_data(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $owner->load(['tasks', 'studySessions']);
        User::whereKey($owner->id)->update(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($owner)->create(['status' => 'completed', 'completed_at' => '2026-10-14 21:00:00']);
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-14 21:00:00']);
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-14 21:00:00', 'ends_at' => '2026-10-14 22:00:00']);
        $foreign = Task::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
        Subtask::factory()->for($foreign)->count(10)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
        StudySession::factory()->for($foreign)->count(10)->create();
        $data = app(ProgressTrendsService::class)->calculate($owner, '2026-10-15', '2026-10-15');
        $this->assertSame('Europe/Kyiv', $data['timezone']);
        $this->assertSame(1, $data['days'][0]['tasks_completed']['total']);
        $this->assertSame(1, $data['days'][0]['subtasks_completed']['total']);
        $this->assertSame(1, $data['days'][0]['study_sessions']['planned']['count']);
        $this->actingAs($owner, 'web');
        $this->reconcile($data, '2026-10-15', '2026-10-15');
    }

    #[DataProvider('corruptReferences')]
    public function test_corrupt_selected_references_fail_closed_for_every_status_without_writes_or_private_errors(string $kind, string $status): void
    {
        config(['app.debug' => true]);
        $task = Task::factory()->create();
        $other = Task::factory()->for($task->user)->create();
        $foreign = Task::factory()->create();
        $child = Subtask::factory()->for($kind === 'foreign_child' ? $foreign : $other)->create();
        StudySession::factory()->for($kind === 'foreign_task' ? $foreign : $task)->create(['user_id' => $task->user_id,
            'subtask_id' => $kind === 'foreign_task' ? null : $child->id, 'status' => $status,
            'completed_at' => $status === 'completed' ? '2026-10-05 10:00:00' : null]);
        $snapshot = $this->snapshot();
        $this->actingAs($task->user, 'web')->getJson($this->url())->assertStatus(500)
            ->assertExactJson(['message' => 'The progress statistics could not be processed.']);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public static function corruptReferences(): iterable
    {
        foreach (['foreign_task', 'foreign_child', 'wrong_owned_parent'] as $kind) {
            foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
                yield [$kind, $status];
            }
        }
    }

    public function test_invalid_persisted_timezone_fails_generically_and_disjoint_envelope_gap_is_not_selected(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Juneau']);
        StudySession::factory()->create(['user_id' => $user->id, 'starts_at' => '1867-10-18 10:00:00', 'ends_at' => '1867-10-18 11:00:00']);
        $this->actingAs($user, 'web')->getJson($this->url('1867-10-18', '1867-10-18'))->assertOk()->assertJsonPath('data.totals', $this->emptyMetrics());
        User::whereKey($user->id)->update(['timezone' => 'Invalid/Timezone']);
        $this->getJson($this->url())->assertStatus(500)->assertExactJson(['message' => 'The progress statistics could not be processed.']);
    }

    #[DataProvider('storageEdges')]
    public function test_mysql_storage_boundaries_and_final_second(string $date, string $instant): void
    {
        $task = Task::factory()->create(['status' => 'completed', 'completed_at' => $instant]);
        Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => $instant]);
        $sessionStart = $date === '9999-12-31' ? '9999-12-31 23:59:58' : $instant;
        StudySession::factory()->for($task)->create(['starts_at' => $sessionStart,
            'ends_at' => CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $sessionStart, 'UTC')->addSecond()->format('Y-m-d H:i:s')]);
        $data = $this->actingAs($task->user, 'web')->getJson($this->url($date, $date))->assertOk()
            ->assertJsonPath('data.days.0.tasks_completed.total', 1)->assertJsonPath('data.days.0.subtasks_completed.total', 1)
            ->assertJsonPath('data.days.0.study_sessions.planned.scheduled_seconds', 1)->json('data');
        $this->reconcile($data, $date, $date);
    }

    public static function storageEdges(): array
    {
        return ProgressStatisticsApiTest::storageEdges();
    }

    public function test_constant_queries_for_one_and_31_days_large_data_repeatability_and_no_business_writes(): void
    {
        Bus::fake();
        Notification::fake();
        Http::fake();
        $task = Task::factory()->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id,
            'starts_at' => '2026-10-15 09:00:00', 'ends_at' => '2026-10-15 10:00:00']);
        Reminder::factory()->for($task->user)->create(['task_id' => $task->id]);
        $this->actingAs($task->user, 'web');
        $snapshot = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $first = $this->getJson($this->url())->assertOk()->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(6, $queries);
        $this->assertSame($first, $this->getJson($this->url())->assertOk()->json('data'));
        $this->assertSame($snapshot, $this->snapshot());
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('for update', $query['query']);
            $this->assertStringNotContainsString('COUNT(*)', $query['query']);
        }
        $sessionQuery = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'from `study_sessions`')))[0];
        $this->assertStringContainsString('`study_sessions`.`user_id` = ?', $sessionQuery['query']);
        $this->assertStringContainsString('`starts_at` >= ?', $sessionQuery['query']);
        $this->assertStringContainsString('`starts_at` < ?', $sessionQuery['query']);
        $this->assertStringNotContainsString('`ends_at` >', $sessionQuery['query']);
        $this->reconcile($first);
        for ($i = 0; $i < 150; $i++) {
            $work = Task::factory()->for($task->user)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
            $step = Subtask::factory()->for($work)->create(['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00']);
            StudySession::factory()->for($work)->create(['subtask_id' => $step->id,
                'starts_at' => '2026-10-15 09:00:00', 'ends_at' => '2026-10-15 10:00:00']);
        }
        foreach ([['2026-10-01', '2026-10-31'], ['2026-10-15', '2026-10-15']] as [$start, $end]) {
            $snapshot = $this->snapshot();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $data = $this->getJson($this->url($start, $end))->assertOk()->assertJsonPath('data.totals.tasks_completed.total', 151)
                ->assertJsonPath('data.totals.subtasks_completed.total', 151)->json('data');
            $this->assertCount(count($queries), DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame($snapshot, $this->snapshot());
            $this->reconcile($data, $start, $end);
        }
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Http::assertNothingSent();
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
