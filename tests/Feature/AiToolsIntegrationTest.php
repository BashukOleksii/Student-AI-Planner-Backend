<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\Schedule\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AiToolsIntegrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16T12:00:00.123456Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function data(User $owner, string $name, array $arguments): array
    {
        $before = $arguments;
        $attributes = $owner->getAttributes();
        $result = app(ToolExecutor::class)->execute($owner, $name, $arguments);
        $this->assertSame(['ok', 'tool', 'data'], array_keys($result), json_encode($result));
        $this->assertTrue($result['ok']);
        $this->assertSame($name, $result['tool']);
        $this->assertSame($before, $arguments);
        $this->assertSame($attributes, $owner->getAttributes());
        // No Eloquent objects, Collections, Carbon values or HTTP Resource objects escape.
        $this->assertSame($result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

        return $result['data'];
    }

    private function period(string $date = '2026-10-20'): array
    {
        return ['start_date' => $date, 'end_date' => $date];
    }

    private function lesson(User $owner, string $start, string $end, array $extra = []): Lesson
    {
        return Lesson::factory()->for($owner)->create(['starts_at' => $start, 'ends_at' => $end, ...$extra]);
    }

    private function persistedSession(Task $task, string $start = '2026-10-20 09:00:00', string $end = '2026-10-20 10:00:00', array $extra = []): StudySession
    {
        return StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end, ...$extra]);
    }

    public function test_schedule_reuses_effective_replacements_owned_presentation_and_local_date_rules(): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $foreign = User::factory()->create();
        $teacher = Teacher::factory()->for($owner)->create();
        $original = $this->lesson($owner, '2026-10-20 06:00:00', '2026-10-20 07:00:00', ['status' => 'replaced']);
        $replacement = $this->lesson($owner, '2026-10-20 07:00:00', '2026-10-20 08:00:00', ['replaces_lesson_id' => $original->id, 'teacher_id' => $teacher->id]);
        $crossing = $this->lesson($owner, '2026-10-19 20:59:59', '2026-10-19 21:00:01');
        $masked = $this->lesson($owner, '2026-10-20 10:00:00', '2026-10-20 11:00:00', [
            'subject_id' => Subject::factory()->for($foreign)->create(['name' => 'PRIVATE SUBJECT'])->id,
            'teacher_id' => Teacher::factory()->for($foreign)->create(['name' => 'PRIVATE TEACHER'])->id,
            'replaces_lesson_id' => $this->lesson($foreign, '2026-10-20 06:00:00', '2026-10-20 07:00:00', ['status' => 'replaced'])->id,
        ]);
        $this->lesson($owner, '2026-10-19 20:00:00', '2026-10-19 21:00:00');
        $this->lesson($owner, '2026-10-20 21:00:00', '2026-10-20 22:00:00');
        $this->lesson($owner, '2026-10-20 12:00:00', '2026-10-20 13:00:00', ['status' => 'cancelled']);
        $this->lesson($owner, '2026-10-20 14:00:00', '2026-10-20 15:00:00')->delete();
        $this->lesson($foreign, '2026-10-20 12:00:00', '2026-10-20 13:00:00');
        $data = $this->data($owner, 'get_schedule_for_date', ['date' => '2026-10-20']);
        $this->assertSame([$crossing->id, $replacement->id, $masked->id], array_column($data['lessons'], 'id'));
        $this->assertSame($original->id, $data['lessons'][1]['replaces_lesson_id']);
        $this->assertSame(['id' => $teacher->id, 'name' => $teacher->name], $data['lessons'][1]['teacher']);
        $this->assertSame('2026-10-20T10:00:00+03:00', $data['lessons'][1]['starts_at']);
        foreach (['subject', 'teacher', 'replaces_lesson_id'] as $field) {
            $this->assertNull($data['lessons'][2][$field]);
        }
        $this->assertStringNotContainsString('PRIVATE', json_encode($data));
        $rest = $this->actingAs($owner, 'web')->getJson('/api/schedule/date/2026-10-20')->assertOk();
        $this->assertSame($rest->json('data'), $data['lessons']);
        $this->assertSame($rest->json('meta'), $data['meta']);
        $this->assertSame([], $this->data($owner, 'get_schedule_for_date', ['date' => '2026-10-22'])['lessons']);
    }

    public static function dstDays(): array
    {
        return [
            ['Europe/Kyiv', '2026-03-29', '2026-03-28 22:00:00', '2026-03-29 21:00:00', 82800],
            ['Europe/Kyiv', '2026-10-25', '2026-10-24 21:00:00', '2026-10-25 22:00:00', 90000],
        ];
    }

    #[DataProvider('dstDays')]
    public function test_schedule_and_sessions_preserve_dst_boundaries(string $zone, string $date, string $start, string $end, int $seconds): void
    {
        $owner = User::factory()->create(['timezone' => $zone]);
        $task = Task::factory()->for($owner)->create();
        $first = $this->lesson($owner, $start, CarbonImmutable::parse($start, 'UTC')->addSecond()->format('Y-m-d H:i:s'));
        $last = $this->lesson($owner, CarbonImmutable::parse($end, 'UTC')->subSecond()->format('Y-m-d H:i:s'), $end);
        $this->lesson($owner, $end, CarbonImmutable::parse($end, 'UTC')->addHour()->format('Y-m-d H:i:s'));
        $session = $this->persistedSession($task, $start, $end);
        $this->persistedSession($task, $end, CarbonImmutable::parse($end, 'UTC')->addHour()->format('Y-m-d H:i:s'));
        $this->assertSame([$first->id, $last->id], array_column($this->data($owner, 'get_schedule_for_date', ['date' => $date])['lessons'], 'id'));
        $sessions = $this->data($owner, 'list_study_sessions', $this->period($date));
        $this->assertSame([$session->id], array_column($sessions['sessions'], 'id'));
        $this->assertSame($seconds, $sessions['sessions'][0]['duration_seconds']);
    }

    public function test_tasks_preserve_filters_pagination_soft_deletion_order_and_safe_subjects(): void
    {
        $owner = User::factory()->create();
        $subject = Subject::factory()->for($owner)->create();
        $foreignSubject = Subject::factory()->create();
        $first = Task::factory()->for($owner)->create(['subject_id' => $subject->id, 'priority' => 3, 'deadline_at' => '2026-10-19 10:00:00']);
        $second = Task::factory()->for($owner)->create(['subject_id' => $subject->id, 'priority' => 3, 'deadline_at' => '2026-10-20 10:00:00']);
        $completed = Task::factory()->for($owner)->create(['status' => 'completed', 'completed_at' => '2026-10-18 10:00:00']);
        $masked = Task::factory()->for($owner)->create(['subject_id' => $foreignSubject->id]);
        Task::factory()->for($owner)->create()->delete();
        Task::factory()->create();
        $filters = ['status' => 'pending', 'priority' => 3, 'subject_id' => $subject->id, 'page' => 2, 'per_page' => 1];
        $data = $this->data($owner, 'list_tasks', $filters);
        $this->assertSame([$second->id], array_column($data['tasks'], 'id'));
        $this->assertSame(['current_page' => 2, 'per_page' => 1, 'total' => 2, 'last_page' => 2], $data['meta']);
        $this->assertSame($subject->id, $data['tasks'][0]['subject_id']);
        $rest = $this->actingAs($owner, 'web')->getJson('/api/tasks?'.http_build_query($filters))->assertOk();
        $this->assertSame($rest->json('data'), $data['tasks']);
        $this->assertSame([$completed->id], array_column($this->data($owner, 'list_tasks', ['status' => 'completed'])['tasks'], 'id'));
        $all = $this->data($owner, 'list_tasks', []);
        $this->assertSame([$first->id, $second->id, $completed->id, $masked->id], array_column($all['tasks'], 'id'));
        $this->assertNull($all['tasks'][3]['subject_id']);
        $this->assertSame(15, $all['meta']['per_page']);
        $this->assertSame([], $this->data($owner, 'list_tasks', ['page' => 999])['tasks']);
        foreach ([$foreignSubject->id, PHP_INT_MAX] as $id) {
            $this->assertSame(['ok' => false, 'tool' => 'list_tasks', 'error' => ['code' => 'invalid_arguments', 'message' => 'Invalid tool arguments.']],
                app(ToolExecutor::class)->execute($owner, 'list_tasks', ['subject_id' => $id]));
        }
        $this->assertSame('invalid_arguments', app(ToolExecutor::class)->execute($owner, 'list_tasks', ['page' => PHP_INT_MAX, 'per_page' => 50])['error']['code']);
    }

    public function test_task_page_size_is_bounded_at_fifty_with_accurate_total(): void
    {
        $owner = User::factory()->create();
        Task::factory()->for($owner)->count(51)->create();
        $data = $this->data($owner, 'list_tasks', ['per_page' => 50]);
        $this->assertCount(50, $data['tasks']);
        $this->assertSame(['current_page' => 1, 'per_page' => 50, 'total' => 51, 'last_page' => 2], $data['meta']);
    }

    public function test_free_slots_preserve_availability_blockers_minimum_and_canonical_utc_seconds(): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $task = Task::factory()->for($owner)->create();
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 2, 'starts_at' => '09:00:00', 'ends_at' => '13:00:00']);
        $this->lesson($owner, '2026-10-20 07:00:00', '2026-10-20 08:00:00');
        $this->persistedSession($task, '2026-10-20 09:00:00', '2026-10-20 09:30:01');
        $this->persistedSession($task, '2026-10-20 06:00:00', '2026-10-20 07:00:00', ['status' => 'completed', 'completed_at' => '2026-10-20 07:00:00']);
        $this->lesson($owner, '2026-10-20 08:00:00', '2026-10-20 09:00:00', ['status' => 'cancelled']);
        $this->lesson(User::factory()->create(), '2026-10-20 08:00:00', '2026-10-20 09:00:00');
        // PF1 free time deliberately does not claim to apply full planner workload/break constraints.
        PlanningPreference::factory()->for($owner)->create(['max_daily_study_minutes' => 1]);
        $count = StudySession::count();
        $data = $this->data($owner, 'find_free_study_slots', [...$this->period(), 'min_slot_minutes' => 30]);
        $this->assertSame([
            ['starts_at' => '2026-10-20T06:00:00Z', 'ends_at' => '2026-10-20T07:00:00Z', 'duration_seconds' => 3600],
            ['starts_at' => '2026-10-20T08:00:00Z', 'ends_at' => '2026-10-20T09:00:00Z', 'duration_seconds' => 3600],
        ], $data['slots']);
        $all = $this->data($owner, 'find_free_study_slots', $this->period());
        $this->assertSame(1799, $all['slots'][2]['duration_seconds']);
        $rest = $this->actingAs($owner, 'web')->getJson('/api/planning/free-slots?start_date=2026-10-20&end_date=2026-10-20&min_slot_minutes=30')->assertOk();
        $this->assertSame($rest->json('data'), $data['slots']);
        $this->assertSame($rest->json('meta'), $data['meta']);
        $this->assertSame([], $this->data($owner, 'find_free_study_slots', [...$this->period(), 'min_slot_minutes' => 65535])['slots']);
        $this->assertSame($count, StudySession::count());
    }

    #[DataProvider('dstDays')]
    public function test_free_time_dst_durations_are_exact(string $zone, string $date, string $start, string $end, int $seconds): void
    {
        $owner = User::factory()->create(['timezone' => $zone]);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 7, 'starts_at' => '00:00:00', 'ends_at' => '24:00:00']);
        $data = $this->data($owner, 'find_free_study_slots', $this->period($date));
        $this->assertSame([['starts_at' => str_replace(' ', 'T', $start).'Z', 'ends_at' => str_replace(' ', 'T', $end).'Z', 'duration_seconds' => $seconds]], $data['slots']);
    }

    public function test_skipped_local_date_returns_empty_slots_sessions_and_zero_filled_trends(): void
    {
        $owner = User::factory()->create(['timezone' => 'Pacific/Apia']);
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 5, 'starts_at' => '00:00:00', 'ends_at' => '24:00:00']);
        $this->persistedSession(Task::factory()->for($owner)->create(), '2011-12-30 09:00:00', '2011-12-30 11:00:00');
        $arguments = $this->period('2011-12-30');
        $this->assertSame([], $this->data($owner, 'find_free_study_slots', $arguments)['slots']);
        $this->assertSame([], $this->data($owner, 'list_study_sessions', $arguments)['sessions']);
        $trends = $this->data($owner, 'get_daily_progress_trends', $arguments);
        $this->assertSame('2011-12-30', $trends['days'][0]['date']);
        $this->assertSame(0, $trends['days'][0]['study_sessions']['planned']['count']);
    }

    public function test_session_history_keeps_original_identities_all_statuses_and_overlap_membership(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create();
        $child = Subtask::factory()->for($task)->create();
        $spanning = $this->persistedSession($task, '2026-10-19 23:59:59', '2026-10-20 00:00:01');
        $this->persistedSession($task, '2026-10-19 23:00:00', '2026-10-20 00:00:00');
        $this->persistedSession($task, '2026-10-21 00:00:00', '2026-10-21 01:00:00');
        $expected = [$spanning->id];
        foreach (StudySessionStatus::cases() as $status) {
            $record = $this->persistedSession($task, extra: ['subtask_id' => $child->id, 'status' => $status,
                'completed_at' => $status === StudySessionStatus::Completed ? '2026-10-20 10:00:00' : null]);
            $expected[] = $record->id;
            if ($status === StudySessionStatus::Rescheduled) {
                $retired = $record;
            }
        }
        $successor = $this->persistedSession($task, '2026-10-20 11:00:00', '2026-10-20 12:00:00', ['rescheduled_from_session_id' => $retired->id]);
        $expected[] = $successor->id;
        $this->persistedSession(Task::factory()->create());
        $child->delete();
        $task->delete();
        $data = $this->data($owner, 'list_study_sessions', $this->period());
        $this->assertSame($expected, array_column($data['sessions'], 'id'));
        $this->assertSame(['planned', 'completed', 'missed', 'rescheduled', 'cancelled'], array_column(array_slice($data['sessions'], 1, 5), 'status'));
        $this->assertNull($data['sessions'][2]['actual_minutes']);
        $this->assertFalse($data['meta']['truncated']);
        $rest = $this->actingAs($owner, 'web')->getJson('/api/study-sessions?start_date=2026-10-20&end_date=2026-10-20')->assertOk();
        $this->assertSame($rest->json('data'), $data['sessions']);
        $this->assertSame($rest->json('meta'), array_intersect_key($data['meta'], $rest->json('meta')));
    }

    public static function sessionCounts(): array
    {
        return [[0], [100], [101]];
    }

    #[DataProvider('sessionCounts')]
    public function test_session_limit_has_honest_total_and_truncation_metadata(int $count): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create();
        $records = StudySession::factory()->for($task)->count($count)->create(['starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00']);
        $data = $this->data($owner, 'list_study_sessions', $this->period());
        $this->assertSame($records->take(100)->modelKeys(), array_column($data['sessions'], 'id'));
        $this->assertSame(['start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'timezone' => 'UTC',
            'limit' => 100, 'returned' => min($count, 100), 'total' => $count, 'truncated' => $count > 100], $data['meta']);
    }

    public static function invalidAttributions(): array
    {
        return [['task', false], ['subtask', false], ['task', true]];
    }

    #[DataProvider('invalidAttributions')]
    public function test_foreign_session_attribution_fails_closed_even_beyond_output_limit(string $kind, bool $afterLimit): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create();
        if ($afterLimit) {
            StudySession::factory()->for($task)->count(100)->create(['starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00']);
        }
        $foreign = Task::factory()->create(['title' => 'FOREIGN PRIVATE TITLE']);
        $extra = $kind === 'task' ? ['task_id' => $foreign->id] : ['subtask_id' => Subtask::factory()->for($foreign)->create()->id];
        $this->persistedSession($task, extra: [...$extra, 'user_id' => $owner->id]);
        foreach (['list_study_sessions', 'get_progress_statistics', 'get_daily_progress_trends'] as $name) {
            $this->assertSame(['ok' => false, 'tool' => $name, 'error' => ['code' => 'execution_failed', 'message' => 'Tool execution failed.']],
                app(ToolExecutor::class)->execute($owner, $name, $this->period()));
        }
        $name = 'compare_progress_periods';
        $this->assertSame(['ok' => false, 'tool' => $name, 'error' => ['code' => 'execution_failed', 'message' => 'Tool execution failed.']],
            app(ToolExecutor::class)->execute($owner, $name, ['baseline_start_date' => '2026-09-20', 'baseline_end_date' => '2026-09-20',
                'current_start_date' => '2026-10-20', 'current_end_date' => '2026-10-20']));
    }

    public function test_all_progress_tools_match_g1_g2a_g2b_endpoints_and_preserve_unknown_actual_duration(): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $foreign = User::factory()->create(['timezone' => 'America/New_York']);
        $task = Task::factory()->for($owner)->create(['status' => 'pending']);
        Subtask::factory()->for($task)->create(['status' => 'completed', 'completed_at' => '2026-10-20 09:00:00']);
        Task::factory()->for($owner)->count(2)->create(['status' => 'completed', 'completed_at' => '2026-09-20 09:00:00']);
        Task::factory()->for($owner)->create(['status' => 'completed', 'completed_at' => '2026-10-20 09:00:00']);
        Task::factory()->for($foreign)->count(3)->create(['status' => 'completed', 'completed_at' => '2026-10-20 09:00:00']);
        $this->persistedSession($task, '2026-09-20 09:00:00', '2026-09-20 10:00:00', ['status' => 'completed', 'completed_at' => '2026-09-20 10:00:00', 'actual_minutes' => 20]);
        $this->persistedSession($task, extra: ['status' => 'completed', 'completed_at' => '2026-10-20 10:00:00']);
        $this->persistedSession($task, '2026-10-20 11:00:00', '2026-10-20 11:30:01', ['status' => 'completed', 'completed_at' => '2026-10-20 11:30:01', 'actual_minutes' => 15]);
        $this->persistedSession(Task::factory()->for($foreign)->create(), extra: ['status' => 'completed', 'completed_at' => '2026-10-20 10:00:00', 'actual_minutes' => 60]);
        $arguments = ['start_date' => '2026-10-20', 'end_date' => '2026-10-22'];
        $comparison = ['baseline_start_date' => '2026-09-20', 'baseline_end_date' => '2026-09-22',
            'current_start_date' => '2026-10-20', 'current_end_date' => '2026-10-22'];
        $this->actingAs($owner, 'web');
        $results = [];
        foreach ([['get_progress_statistics', '/api/statistics/progress', $arguments],
            ['get_daily_progress_trends', '/api/statistics/trends', $arguments],
            ['compare_progress_periods', '/api/statistics/reports/comparison', $comparison]] as [$name, $url, $input]) {
            $data = $this->data($owner, $name, $input);
            $this->assertSame($this->getJson($url.'?'.http_build_query($input))->assertOk()->json('data'), $data);
            $this->assertSame('Europe/Kyiv', $data['timezone']);
            $this->assertSame('2026-10-16T12:00:00.123456Z', $data['as_of_utc']);
            $results[$name] = $data;
        }
        $summary = $results['get_progress_statistics'];
        $trends = $results['get_daily_progress_trends'];
        $report = $results['compare_progress_periods'];
        $this->assertSame(1, $summary['tasks']['current']['pending']);
        $this->assertSame(1, $summary['tasks']['completed_in_period']['total']);
        $this->assertSame(1, $summary['subtasks']['completed_in_period']['total']);
        $this->assertSame(15, $summary['study_sessions']['started_in_period']['completed_actual_minutes']);
        $this->assertSame(1, $summary['study_sessions']['started_in_period']['completed_without_actual_minutes']);
        $this->assertSame(5401, $summary['study_sessions']['started_in_period']['completed']['scheduled_seconds']);
        $this->assertSame($summary['study_sessions']['started_in_period'], $trends['totals']['study_sessions']);
        $this->assertSame($summary['tasks']['completed_in_period'], $trends['totals']['tasks_completed']);
        $this->assertSame(0, $trends['days'][1]['tasks_completed']['total']);
        $this->assertSame(0, $trends['days'][2]['study_sessions']['completed']['count']);
        $this->assertSame(-1, $report['delta']['tasks_completed']['total']);
        $this->assertSame('decreased', $report['observations']['task_completions']);
        $this->assertSame(-5, $report['delta']['study_sessions']['completed_actual_minutes']);
        $this->assertTrue($report['observations']['current_has_unknown_actual_minutes']);
        $this->assertFalse($report['observations']['baseline_has_unknown_actual_minutes']);
        $this->assertFalse($report['comparison']['historical_snapshot']);
        $this->assertStringNotContainsString('productivity', json_encode($results));
        $this->assertStringNotContainsString('percent', json_encode($report));
    }

    public function test_explicit_trusted_owner_and_fresh_persisted_timezone_override_global_auth_and_stale_profile(): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $foreign = User::factory()->create(['timezone' => 'America/New_York']);
        $ownedTask = Task::factory()->for($owner)->create();
        $ownedLesson = $this->lesson($owner, '2026-10-19 21:00:00', '2026-10-19 22:00:00');
        $ownedSession = $this->persistedSession($ownedTask, '2026-10-19 21:00:00', '2026-10-19 22:00:00');
        $this->lesson($foreign, '2026-10-20 07:00:00', '2026-10-20 08:00:00');
        $this->persistedSession(Task::factory()->for($foreign)->create());
        StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 2]);
        StudyAvailabilityWindow::factory()->for($foreign)->create(['day_of_week' => 2, 'starts_at' => '12:00:00', 'ends_at' => '14:00:00']);
        $this->actingAs($foreign, 'web');
        $owner->timezone = 'UTC';
        foreach ($this->calls() as [$name, $arguments]) {
            $data = $this->data($owner, $name, $arguments);
            if ($name !== 'list_tasks') {
                $this->assertSame('Europe/Kyiv', $data['timezone'] ?? $data['meta']['timezone'] ?? null, $name);
            }
            if ($name === 'get_schedule_for_date') {
                $this->assertSame([$ownedLesson->id], array_column($data['lessons'], 'id'));
            } elseif ($name === 'list_tasks') {
                $this->assertSame([$ownedTask->id], array_column($data['tasks'], 'id'));
            } elseif ($name === 'list_study_sessions') {
                $this->assertSame([$ownedSession->id], array_column($data['sessions'], 'id'));
            } elseif ($name === 'find_free_study_slots') {
                $this->assertSame('2026-10-20T06:00:00Z', $data['slots'][0]['starts_at']);
            }
        }
        $this->assertSame('UTC', $owner->timezone);
        $this->assertSame($foreign->id, auth()->id());
    }

    public static function malformedArguments(): array
    {
        return [
            ['list_tasks', ['user_id' => 999]], ['list_tasks', ['timezone' => 'UTC']],
            ['list_tasks', ['page' => '1']], ['list_tasks', ['priority' => 2.0]], ['list_tasks', ['subject_id' => true]],
            ['list_tasks', ['status' => ['pending']]], ['list_tasks', ['status' => null]],
            ['get_schedule_for_date', []], ['get_schedule_for_date', ['date' => '2026-02-29']],
            ['find_free_study_slots', ['start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'min_slot_minutes' => '30']],
            ['get_progress_statistics', ['start_date' => '2026-10-01', 'end_date' => '2026-11-01']],
            ['compare_progress_periods', ['baseline_start_date' => '2026-10-01', 'baseline_end_date' => '2026-10-07', 'current_start_date' => '2026-10-07', 'current_end_date' => '2026-10-13']],
        ];
    }

    #[DataProvider('malformedArguments')]
    public function test_invalid_arguments_fail_before_database_dispatch(string $name, array $arguments): void
    {
        $owner = User::factory()->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(ToolExecutor::class)->execute($owner, $name, $arguments);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame(['ok' => false, 'tool' => $name, 'error' => ['code' => 'invalid_arguments', 'message' => 'Invalid tool arguments.']], $result);
        $this->assertSame([], $queries);
    }

    public function test_unknown_tools_and_unexpected_sql_errors_are_safe(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->mock(ScheduleService::class);
        $schedule->shouldReceive('date')->once()->andThrow(new QueryException('mysql', 'SELECT private FROM secrets WHERE foreign_id = 999', [], new RuntimeException('credentials')));
        $executor = app(ToolExecutor::class);
        $this->assertSame(['ok' => false, 'tool' => 'App\\Services\\Tasks\\TaskService::delete', 'error' => ['code' => 'unknown_tool', 'message' => 'Unknown tool.']],
            $executor->execute($owner, 'App\\Services\\Tasks\\TaskService::delete', []));
        $this->assertSame(['ok' => false, 'tool' => 'get_schedule_for_date', 'error' => ['code' => 'execution_failed', 'message' => 'Tool execution failed.']],
            $executor->execute($owner, 'get_schedule_for_date', ['date' => '2026-10-20']));
    }

    private function calls(): array
    {
        return [
            ['get_schedule_for_date', ['date' => '2026-10-20']],
            ['list_tasks', []],
            ['find_free_study_slots', $this->period()],
            ['list_study_sessions', $this->period()],
            ['get_progress_statistics', $this->period()],
            ['get_daily_progress_trends', $this->period()],
            ['compare_progress_periods', ['baseline_start_date' => '2026-09-20', 'baseline_end_date' => '2026-09-20',
                'current_start_date' => '2026-10-20', 'current_end_date' => '2026-10-20']],
        ];
    }

    public function test_every_registered_tool_is_read_only_with_exact_database_snapshots_and_no_external_side_effects(): void
    {
        $owners = User::factory()->count(2)->create(['timezone' => 'Europe/Kyiv']);
        foreach ($owners as $owner) {
            $task = Task::factory()->for($owner)->create(['deadline_at' => '2026-10-21 09:00:00']);
            Subtask::factory()->for($task)->create();
            StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 2]);
            PlanningPreference::factory()->for($owner)->create();
            $this->lesson($owner, '2026-10-20 06:00:00', '2026-10-20 06:30:00');
            $this->persistedSession($task);
            $reminder = Reminder::factory()->for($owner)->create(['task_id' => $task->id]);
            $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'reminder', 'data' => ['message' => 'Unread'], 'reminder_id' => $reminder->id]);
            AiMessage::factory()->for(AiConversation::factory()->for($owner)->create(), 'conversation')->create();
        }
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        Bus::fake();
        Notification::fake();
        $calls = $this->calls();
        $this->assertSame(array_column(app(ToolRegistry::class)->definitions(), 'name'), array_column($calls, 0));
        foreach ($owners as $owner) {
            foreach ($calls as [$name, $arguments]) {
                $before = $this->snapshot();
                DB::enableQueryLog();
                DB::flushQueryLog();
                $level = DB::transactionLevel();
                try {
                    $this->data($owner, $name, $arguments);
                    $queries = DB::getQueryLog();
                } finally {
                    DB::disableQueryLog();
                }
                $this->assertSame($level, DB::transactionLevel());
                $this->assertNotEmpty($queries);
                foreach ($queries as $query) {
                    $this->assertStringStartsWith('select ', $query['query']);
                    $this->assertStringNotContainsString('for update', $query['query']);
                    $this->assertStringNotContainsString('lock in share mode', $query['query']);
                }
                $this->assertSame($before, $this->snapshot(), $name);
            }
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
    }

    public function test_empty_data_is_valid_for_all_seven_tools_without_materializing_defaults(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        foreach ($this->calls() as [$name, $arguments]) {
            $before = $this->snapshot();
            $data = $this->data($owner, $name, $arguments);
            $this->assertSame($before, $this->snapshot());
            if (in_array($name, ['get_schedule_for_date', 'list_tasks', 'find_free_study_slots', 'list_study_sessions'], true)) {
                $this->assertSame([], reset($data));
            }
        }
        $this->assertDatabaseCount('planning_preferences', 0);
        $this->assertDatabaseCount('ai_conversations', 0);
        $this->assertDatabaseCount('ai_messages', 0);
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
