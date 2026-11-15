<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\ScheduleImportBatch;
use App\Models\SemesterSchedule;
use App\Models\StudyAvailabilityWindow;
use App\Models\Subject;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use App\Services\Planning\CalendarBusyIntervalService;
use App\Services\Planning\FreeTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('workflow')]
#[Group('mysql')]
class CompactSemesterPlanningCalendarTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-01T00:00:00Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function schedule(?User $owner = null, array $dates = []): SemesterSchedule
    {
        $owner ??= User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-11-30', ...$dates]);
        foreach ($owner->studyAvailabilityWindows()->exists() ? [] : range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => $day, 'starts_at' => '09:00:00', 'ends_at' => '15:00:00']);
        }

        return $owner->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'Planning', 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']);
    }

    private function rule(SemesterSchedule $schedule, array $changes = []): WeeklyLessonRule
    {
        return $schedule->weeklyLessonRules()->create(['user_id' => $schedule->user_id, 'weekday' => 2, 'week_pattern' => 'all', 'pair_number' => 1, 'starts_at_local' => '10:00:00', 'ends_at_local' => '11:20:00', 'subject_id' => Subject::factory()->for($schedule->user)->create()->id, ...$changes])->refresh();
    }

    private function exception(SemesterSchedule $schedule, string $date, array $lessons, array $changes = []): void
    {
        $exception = $schedule->dateExceptions()->create(['user_id' => $schedule->user_id, 'occurs_on' => $date, 'kind' => 'replacement', ...$changes]);
        foreach ($lessons as $row) {
            $exception->lessons()->create(['user_id' => $schedule->user_id, 'semester_schedule_id' => $schedule->id, ...$row]);
        }
    }

    private function busy(User $owner, string $start, string $end, bool $clip = true): array
    {
        return app(CalendarBusyIntervalService::class)->between($owner, strtotime($start), strtotime($end), $clip);
    }

    private function free(User $owner, string $date): array
    {
        return array_map(fn ($row) => [gmdate('H:i', $row['start']), gmdate('H:i', $row['end'])], app(FreeTimeService::class)->calculate($owner, $date, $date));
    }

    public function test_semester_only_parity_manual_edits_and_rest_contract(): void
    {
        $schedule = $this->schedule();
        $numerator = $this->rule($schedule, ['week_pattern' => 'numerator']);
        $this->rule($schedule, ['week_pattern' => 'denominator', 'starts_at_local' => '12:00:00', 'ends_at_local' => '13:00:00']);
        $this->assertSame([['06:00', '07:00'], ['08:20', '12:00']], $this->free($schedule->user, '2026-09-08'));
        $this->assertSame([['06:00', '09:00'], ['10:00', '12:00']], $this->free($schedule->user, '2026-09-15'));
        $this->actingAs($schedule->user)->getJson('/api/planning/free-slots?start_date=2026-09-08&end_date=2026-09-08')->assertOk()->assertExactJson(['data' => [['starts_at' => '2026-09-08T06:00:00Z', 'ends_at' => '2026-09-08T07:00:00Z', 'duration_seconds' => 3600], ['starts_at' => '2026-09-08T08:20:00Z', 'ends_at' => '2026-09-08T12:00:00Z', 'duration_seconds' => 13200]], 'meta' => ['timezone' => 'Europe/Kyiv', 'start_date' => '2026-09-08', 'end_date' => '2026-09-08']]);
        $numerator->update(['starts_at_local' => '11:00:00', 'ends_at_local' => '12:00:00', 'is_manually_managed' => true]);
        $this->assertSame([['06:00', '08:00'], ['09:00', '12:00']], $this->free($schedule->user, '2026-09-08'));
        $this->assertDatabaseCount('lessons', 0);
    }

    #[DataProvider('exceptions')]
    public function test_replacements_cancellation_extras_and_selected_saturday_snapshots(string $date, array $times, array $metadata, array $expected): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule, ['week_pattern' => 'numerator']);
        $lessons = [];
        foreach ($times as $index => [$start, $end]) {
            $lessons[] = ['subject_id' => $rule->subject_id, 'pair_number' => $index + 1, 'starts_at_local' => $start, 'ends_at_local' => $end, 'source_weekly_rule_id' => $index === 0 ? $rule->id : null];
        }
        $this->exception($schedule, $date, $lessons, $metadata);
        $this->assertSame($expected, $this->free($schedule->user, $date));
        $this->assertDatabaseCount('lessons', 0);
    }

    public static function exceptions(): array
    {
        return ['cancelled day' => ['2026-09-08', [], [], [0 => [0 => '06:00', 1 => '12:00']]]];
    }

    public function test_legacy_imports_and_their_replacements_are_suppressed_only_in_their_own_authoritative_period(): void
    {
        $schedule = $this->schedule();
        $owner = $schedule->user;
        $this->rule($schedule);
        $batch = ScheduleImportBatch::factory()->for($owner)->for($schedule->academicPeriod)->create();
        $import = Lesson::factory()->for($owner)->create(['academic_period_id' => $schedule->academic_period_id, 'schedule_import_batch_id' => $batch->id, 'import_fingerprint' => str_repeat('a', 64), 'starts_at' => '2026-09-08 06:00:00', 'ends_at' => '2026-09-08 07:00:00']);
        $original = Lesson::factory()->for($owner)->create(['academic_period_id' => $schedule->academic_period_id, 'schedule_import_batch_id' => $batch->id, 'status' => LessonStatus::Replaced]);
        $replacement = Lesson::factory()->for($owner)->create(['replaces_lesson_id' => $original->id, 'starts_at' => '2026-09-08 09:00:00', 'ends_at' => '2026-09-08 10:00:00']);
        $original->delete();
        $manual = Lesson::factory()->for($owner)->create(['academic_period_id' => $schedule->academic_period_id, 'starts_at' => '2026-09-08 08:00:00', 'ends_at' => '2026-09-08 09:00:00']);
        $otherPeriod = AcademicPeriod::factory()->for($owner)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-10-01']);
        $otherImport = Lesson::factory()->for($owner)->create(['academic_period_id' => $otherPeriod->id, 'import_fingerprint' => str_repeat('b', 64), 'starts_at' => '2026-09-08 10:00:00', 'ends_at' => '2026-09-08 11:00:00']);
        $before = [$import->refresh()->getAttributes(), $replacement->refresh()->getAttributes()];
        $this->assertSame([['06:00', '07:00'], ['09:00', '10:00'], ['11:00', '12:00']], $this->free($owner, '2026-09-08'));
        $ids = array_column($this->busy($owner, '2026-09-08T00:00:00Z', '2026-09-09T00:00:00Z'), 'lesson_id');
        $this->assertSame([$manual->id, $otherImport->id], $ids);
        $this->assertSame($before, [$import->fresh()->getAttributes(), $replacement->fresh()->getAttributes()]);
        $import->update(['starts_at' => '2026-09-06 20:00:00', 'ends_at' => '2026-09-07 00:00:00']);
        $outside = $this->busy($owner, '2026-09-06T19:00:00Z', '2026-09-07T01:00:00Z');
        $this->assertSame(strtotime('2026-09-06T21:00:00Z'), $outside[0]['end']);
        $replacement->update(['starts_at' => '2026-12-01 08:00:00', 'ends_at' => '2026-12-01 09:00:00']);
        $this->assertCount(1, $this->busy($owner, '2026-12-01T00:00:00Z', '2026-12-02T00:00:00Z'));
    }

    public function test_overlapping_semesters_are_unioned_without_losing_source_identity(): void
    {
        $schedule = $this->schedule();
        $this->rule($schedule);
        $second = $this->schedule($schedule->user);
        $this->rule($second, ['starts_at_local' => '11:00:00', 'ends_at_local' => '12:00:00']);
        $this->assertSame([['06:00', '07:00'], ['09:00', '12:00']], $this->free($schedule->user, '2026-09-08'));
        $rows = $this->busy($schedule->user, '2026-09-08T07:30:00Z', '2026-09-08T08:30:00Z');
        $this->assertCount(2, $rows);
        $this->assertCount(2, array_unique(array_column($rows, 'id')));
        $this->assertSame(strtotime('2026-09-08T07:30:00Z'), $rows[0]['start']);
        $this->assertSame(strtotime('2026-09-08T08:30:00Z'), $rows[1]['end']);
    }
}
