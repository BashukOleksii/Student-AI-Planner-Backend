<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AI\Tools\ToolExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('ai')]
#[Group('mysql')]
class CompactAiToolsIntegrationTest extends TestCase
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
        $this->lesson($owner, '2026-10-19 20:00:00', '2026-10-19 21:00:00');
        $this->lesson($owner, '2026-10-20 21:00:00', '2026-10-20 22:00:00');
        $this->lesson($owner, '2026-10-20 12:00:00', '2026-10-20 13:00:00', ['status' => 'cancelled']);
        $this->lesson($owner, '2026-10-20 14:00:00', '2026-10-20 15:00:00')->delete();
        $this->lesson($foreign, '2026-10-20 12:00:00', '2026-10-20 13:00:00');
        $data = $this->data($owner, 'get_schedule_for_date', ['date' => '2026-10-20']);
        $this->assertSame([$crossing->id, $replacement->id], array_column($data['lessons'], 'id'));
        $this->assertSame(['legacy_lesson:'.$crossing->id, 'legacy_lesson:'.$replacement->id], array_column($data['lessons'], 'source_identity'));
        $this->assertSame(['legacy_lesson', 'legacy_lesson'], array_column($data['lessons'], 'source_type'));
        $this->assertSame($original->id, $data['lessons'][1]['replaces_lesson_id']);
        $this->assertSame(['id' => $teacher->id, 'name' => $teacher->name], $data['lessons'][1]['teacher']);
        $this->assertSame('2026-10-20T10:00:00+03:00', $data['lessons'][1]['starts_at']);
        $this->assertSame('2026-10-20T00:00:01+03:00', $data['lessons'][0]['ends_at']);
        $rest = $this->actingAs($owner, 'web')->getJson('/api/schedule/date/2026-10-20')->assertOk();
        $sharedFields = ['id', 'subject', 'teacher', 'type', 'room', 'starts_at', 'ends_at', 'replaces_lesson_id'];
        $normalize = fn (array $rows) => array_map(fn ($row) => [...array_intersect_key($row, array_flip($sharedFields)), 'subject' => $row['subject'] === null ? null : array_intersect_key($row['subject'], array_flip(['id', 'name']))], $rows);
        $this->assertSame($normalize($rest->json('data')), $normalize($data['lessons']));
        $this->assertSame($rest->json('meta'), array_intersect_key($data['meta'], array_flip(['timezone', 'start_date', 'end_date'])));
        $this->assertSame([], $this->data($owner, 'get_schedule_for_date', ['date' => '2026-10-22'])['lessons']);
    }

    public function test_task_page_size_is_bounded_at_fifty_with_accurate_total(): void
    {
        $owner = User::factory()->create();
        Task::factory()->for($owner)->count(51)->create();
        $data = $this->data($owner, 'list_tasks', ['per_page' => 50]);
        $this->assertCount(50, $data['tasks']);
        $this->assertSame(['current_page' => 1, 'per_page' => 50, 'total' => 51, 'last_page' => 2], $data['meta']);
    }

    public function test_schedule_combines_legacy_and_semester_sources_with_distinct_identities(): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create(['starts_on' => '2026-10-19', 'ends_on' => '2026-10-25']);
        $schedule = $owner->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'ПІ-231', 'parity_anchor_on' => '2026-10-19', 'first_week_parity' => 'numerator']);
        $subject = Subject::factory()->for($owner)->create();
        $rule = $schedule->weeklyLessonRules()->create(['user_id' => $owner->id, 'weekday' => 2, 'week_pattern' => 'all', 'pair_number' => 1, 'pair_label' => '1', 'starts_at_local' => '08:00:00', 'ends_at_local' => '09:00:00', 'subject_id' => $subject->id]);
        $legacy = $this->lesson($owner, '2026-10-20 06:00:00', '2026-10-20 07:00:00');
        $lessons = $this->data($owner, 'get_schedule_for_date', ['date' => '2026-10-20'])['lessons'];
        $this->assertSame(['semester_occurrence', 'legacy_lesson'], array_column($lessons, 'source_type'));
        $this->assertSame('semester:'.$schedule->id.':2026-10-20:rule:'.$rule->id, $lessons[0]['source_identity']);
        $this->assertSame($lessons[0]['source_identity'], $lessons[0]['id']);
        $this->assertSame('legacy_lesson:'.$legacy->id, $lessons[1]['source_identity']);
        $this->assertSame($legacy->id, $lessons[1]['id']);
        $this->assertSame('2026-10-20T08:00:00+03:00', $lessons[0]['starts_at']);
        $this->assertSame('2026-10-20T09:00:00+03:00', $lessons[1]['starts_at']);
    }

    public static function dstDays(): array
    {
        return [0 => ['Europe/Kyiv', '2026-03-29', '2026-03-28 22:00:00', '2026-03-29 21:00:00', 82800]];
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
            $this->assertSame(['ok' => false, 'tool' => 'list_tasks', 'error' => ['code' => 'invalid_arguments', 'message' => 'Invalid tool arguments.']], app(ToolExecutor::class)->execute($owner, 'list_tasks', ['subject_id' => $id]));
        }
        $this->assertSame('invalid_arguments', app(ToolExecutor::class)->execute($owner, 'list_tasks', ['page' => PHP_INT_MAX, 'per_page' => 50])['error']['code']);
    }
}
