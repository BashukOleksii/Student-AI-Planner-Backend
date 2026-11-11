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
use App\Services\Schedule\SemesterScheduleManagementService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Deferred until disposable MySQL verification; never includes self-study in the academic calendar. */
class AcademicCalendarApiTest extends TestCase
{
    use DatabaseTruncation;

    public function test_unified_day_week_and_today_preserve_owned_source_identity_and_legacy_contracts(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 20)->setTime(8, 0));
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create([
            'starts_on' => '2026-10-19', 'ends_on' => '2026-10-25',
        ]);
        $schedule = $owner->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'Calendar',
            'parity_anchor_on' => '2026-10-19', 'first_week_parity' => 'numerator']);
        $teacher = Teacher::factory()->for($owner)->create();
        $subject = Subject::factory()->for($owner)->create();
        $manager = app(SemesterScheduleManagementService::class);
        $manager->createRule($owner, $schedule, ['weekday' => 2, 'week_pattern' => 'numerator', 'pair_number' => 1,
            'starts_at_local' => '08:00:00', 'ends_at_local' => '09:00:00', 'subject_id' => $subject->id,
            'teacher_id' => $teacher->id, 'lesson_type' => null,
            'schedule_token' => $manager->read($owner, $schedule)['meta']['schedule_token']]);
        $legacy = Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-20 07:00:00', 'ends_at' => '2026-10-20 08:00:00']);
        $task = Task::factory()->for($owner)->create();
        StudySession::factory()->for($task)->create(['starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00']);
        Lesson::factory()->create(['starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00']);
        $before = DB::table('lessons')->get()->toJson();
        $this->getJson('/api/academic-calendar/today')->assertUnauthorized();
        $this->actingAs($owner, 'web');
        $day = $this->getJson('/api/academic-calendar/date/2026-10-20')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source_type', 'semester_occurrence')->assertJsonPath('data.0.type', null)
            ->assertJsonPath('data.0.starts_at', '2026-10-20T08:00:00+03:00')
            ->assertJsonPath('data.1.id', $legacy->id)->assertJsonPath('data.1.lesson_id', $legacy->id)
            ->assertJsonPath('meta.truncated', false)->json('data');
        $this->assertIsString($day[0]['id']);
        $this->assertSame($day[0]['id'], $day[0]['source_identity']);
        $this->assertNotNull($day[0]['weekly_lesson_rule_id']);
        $this->assertSame($day, $this->getJson('/api/academic-calendar/today')->assertOk()->json('data'));
        $this->assertSame($day, $this->getJson('/api/academic-calendar/week/2026-10-20')->assertOk()->json('data'));
        $this->getJson('/api/schedule/date/2026-10-20')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/academic-calendar/date/2026-02-30')->assertUnprocessable();
        $this->assertSame($before, DB::table('lessons')->get()->toJson());
    }

    public function test_truncation_reports_total_instead_of_silently_omitting_academic_lessons(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        Lesson::factory()->count(101)->for($owner)->create(['starts_at' => '2026-10-20 07:00:00', 'ends_at' => '2026-10-20 08:00:00']);
        $this->actingAs($owner, 'web')->getJson('/api/academic-calendar/date/2026-10-20')->assertOk()
            ->assertJsonCount(100, 'data')->assertJsonPath('meta.total', 101)->assertJsonPath('meta.returned', 100)
            ->assertJsonPath('meta.truncated', true);
    }
    public function test_unexpected_calendar_errors_do_not_disclose_sql_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $owner = User::factory()->create();
        $reader = $this->mock(\App\Services\AI\Tools\EffectiveScheduleReadService::class);
        $reader->shouldReceive('range')->once()->andThrow(new \RuntimeException('SELECT private FROM secrets; credential'));
        $this->actingAs($owner, 'web')->getJson('/api/academic-calendar/date/2026-10-20')->assertStatus(500)
            ->assertExactJson(['message' => 'The academic calendar could not be processed.']);
    }

}
