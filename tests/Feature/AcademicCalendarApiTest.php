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
use App\Services\AI\Tools\EffectiveScheduleReadService;
use App\Services\Schedule\SemesterScheduleManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Unified academic reads exclude self-study; fixtures clean up before transaction-based API suites. */
#[Group('mysql')]
class AcademicCalendarApiTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    #[Group('smoke')]
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
        $reader = $this->mock(EffectiveScheduleReadService::class);
        $reader->shouldReceive('range')->once()->andThrow(new \RuntimeException('SELECT private FROM secrets; credential'));
        $this->actingAs($owner, 'web')->getJson('/api/academic-calendar/date/2026-10-20')->assertStatus(500)
            ->assertExactJson(['message' => 'The academic calendar could not be processed.']);
    }

    #[DataProvider('dstDates')]
    public function test_unified_reads_preserve_dst_instants_and_cross_midnight_occupancy_without_self_study(string $date, string $localStart, string $localEnd): void
    {
        $this->travelTo(CarbonImmutable::parse($date.'T04:00:00Z'));
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $monday = CarbonImmutable::parse($date, 'UTC')->subDays(6)->toDateString();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create(['starts_on' => $monday, 'ends_on' => $date]);
        $schedule = $owner->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'DST',
            'parity_anchor_on' => $monday, 'first_week_parity' => 'numerator']);
        $subject = Subject::factory()->for($owner)->create();
        $teacher = Teacher::factory()->for($owner)->create();
        $manager = app(SemesterScheduleManagementService::class);
        $manager->replaceDate($owner, $schedule, $date, ['mode' => 'replace', 'lessons' => [['pair_number' => 1,
            'starts_at_local' => '01:00:00', 'ends_at_local' => '02:00:00', 'subject_id' => $subject->id, 'teacher_id' => $teacher->id]],
            'schedule_token' => $manager->read($owner, $schedule)['meta']['schedule_token']]);
        $dst = Lesson::factory()->for($owner)->create(['starts_at' => $date.' 00:30:00', 'ends_at' => $date.' 01:30:00']);
        $previous = CarbonImmutable::parse($date)->subDay()->toDateString();
        $cross = Lesson::factory()->for($owner)->create(['starts_at' => $previous.' 20:30:00', 'ends_at' => $date.' 00:30:00']);
        StudySession::factory()->for(Task::factory()->for($owner)->create())->create(['starts_at' => $date.' 09:00:00', 'ends_at' => $date.' 10:00:00']);
        $this->actingAs($owner, 'web');
        $rows = $this->getJson('/api/academic-calendar/date/'.$date)->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertSame($rows, $this->getJson('/api/academic-calendar/today')->assertOk()->json('data'));
        $this->assertSame($rows, $this->getJson('/api/academic-calendar/week/'.$date)->assertOk()->json('data'));
        $lesson = collect($rows)->firstWhere('lesson_id', $dst->id);
        $this->assertSame($localStart, $lesson['starts_at']);
        $this->assertSame($localEnd, $lesson['ends_at']);
        $this->assertSame(3600, strtotime($lesson['ends_at']) - strtotime($lesson['starts_at']));
        $this->assertSame($cross->id, $rows[0]['lesson_id']);
        $this->assertSame($previous, $rows[0]['occurs_on']);
        $this->assertCount(1, array_filter($rows, fn ($row) => $row['source_type'] === 'semester_occurrence'));
        $this->assertCount(3, array_unique(array_column($rows, 'source_identity')));
    }

    public static function dstDates(): array
    {
        return [['2026-03-29', '2026-03-29T02:30:00+02:00', '2026-03-29T04:30:00+03:00'],
            ['2026-10-25', '2026-10-25T03:30:00+03:00', '2026-10-25T03:30:00+02:00']];
    }
}
