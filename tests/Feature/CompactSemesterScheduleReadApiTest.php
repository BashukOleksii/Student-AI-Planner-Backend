<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\ScheduleDateException;
use App\Models\SemesterSchedule;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsBatchedReads;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactSemesterScheduleReadApiTest extends TestCase
{
    use AssertsBatchedReads;
    use RefreshDatabase;

    private function schedule(?User $user = null): SemesterSchedule
    {
        $user ??= User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($user)->create();
        $period = AcademicPeriod::factory()->for($user)->for($institution)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);

        return $user->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'ПІ-231', 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator'])->refresh();
    }

    private function rule(SemesterSchedule $schedule, array $changes = []): WeeklyLessonRule
    {
        return $schedule->weeklyLessonRules()->create(array_replace(['user_id' => $schedule->user_id, 'weekday' => 2, 'week_pattern' => 'all', 'pair_number' => 4, 'pair_label' => '7-8', 'starts_at_local' => '13:20:00', 'ends_at_local' => '14:40:00', 'subject_id' => Subject::factory()->for($schedule->user)->create()->id], $changes))->refresh();
    }

    private function snapshot(SemesterSchedule $schedule, WeeklyLessonRule $rule, string $date, string $kind = 'replacement', array $changes = []): ScheduleDateException
    {
        $exception = $schedule->dateExceptions()->create(['user_id' => $schedule->user_id, 'occurs_on' => $date, 'kind' => $kind, 'copied_weekday' => $kind === 'working_saturday' ? 5 : null]);
        $exception->lessons()->create(array_replace($rule->only(['user_id', 'semester_schedule_id', 'pair_number', 'pair_label', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room']), ['source_weekly_rule_id' => $rule->id], $changes));

        return $exception;
    }

    private function url(SemesterSchedule $schedule, string $suffix = ''): string
    {
        return '/api/semester-schedules/'.$schedule->id.$suffix;
    }

    public function test_list_contains_only_owned_schedules_and_empty_list_needs_no_creation(): void
    {
        $user = User::factory()->create();
        $foreign = $this->schedule();
        $this->actingAs($user, 'web')->getJson('/api/semester-schedules')->assertExactJson(['data' => []]);
        $first = $this->schedule($user);
        $second = $this->schedule($user);
        $this->getJson('/api/semester-schedules')->assertOk()->assertJsonPath('data.*.id', [$first->id, $second->id]);
    }

    public function test_detail_contains_owned_academic_context_and_explicit_anchor(): void
    {
        $schedule = $this->schedule();
        $period = $schedule->academicPeriod;
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule))->assertExactJson(['data' => ['id' => $schedule->id, 'group_name' => 'ПІ-231', 'academic_period' => ['id' => $period->id, 'name' => $period->name, 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-20'], 'education_institution' => $period->educationInstitution->only(['id', 'name']), 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']]);
    }

    #[DataProvider('invalidDates')]
    public function test_date_validation_cannot_be_overridden_by_query_arguments(string $suffix): void
    {
        $schedule = $this->schedule();
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule, $suffix).'?date=2026-09-08')->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public static function invalidDates(): array
    {
        return [0 => ['/date/invalid']];
    }

    public function test_day_occurrence_is_a_stable_virtual_resource_with_optional_metadata(): void
    {
        $schedule = $this->schedule();
        $teacher = Teacher::factory()->for($schedule->user)->create();
        $rule = $this->rule($schedule, ['teacher_id' => $teacher->id, 'room' => '221']);
        $url = $this->url($schedule, '/date/2026-09-08');
        $expected = ['data' => [['occurrence_id' => 'semester:'.$schedule->id.':2026-09-08:rule:'.$rule->id, 'occurs_on' => '2026-09-08', 'source' => 'base', 'change_kind' => 'base', 'pair_number' => 4, 'pair_label' => '7-8', 'subject' => $rule->subject->only(['id', 'name', 'code', 'color']), 'teacher' => $teacher->only(['id', 'name']), 'room' => '221', 'starts_at' => '2026-09-08T13:20:00+03:00', 'ends_at' => '2026-09-08T14:40:00+03:00', 'weekly_lesson_rule_id' => $rule->id, 'schedule_date_exception_id' => null, 'lesson_type' => null, 'teacher_id' => $teacher->id, 'teacher_ids' => [$teacher->id], 'teachers' => [$teacher->only(['id', 'name'])]]], 'meta' => ['timezone' => 'Europe/Kyiv', 'semester_schedule_id' => $schedule->id, 'start_date' => '2026-09-08', 'end_date' => '2026-09-08']];
        $this->actingAs($schedule->user, 'web')->getJson($url)->assertExactJson($expected);
        $this->getJson($url)->assertExactJson($expected);
        $rule->update(['teacher_id' => null, 'pair_label' => null]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.teacher', null)->assertJsonPath('data.0.pair_label', null)->assertJsonPath('data.0.occurrence_id', $expected['data'][0]['occurrence_id']);
    }

    public function test_iso_week_preserves_pair_numbers_and_alternates_tuesday(): void
    {
        $schedule = $this->schedule();
        $monday = $this->rule($schedule, ['weekday' => 1, 'pair_number' => 1]);
        $numerator = $this->rule($schedule, ['week_pattern' => 'numerator']);
        $denominator = $this->rule($schedule, ['week_pattern' => 'denominator']);
        $this->actingAs($schedule->user, 'web');
        foreach (['2026-09-13' => ['2026-09-07', '2026-09-13', $numerator], '2026-09-14' => ['2026-09-14', '2026-09-20', $denominator]] as $anchor => [$start, $end, $tuesday]) {
            $this->getJson($this->url($schedule, '/week/'.$anchor))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.*.weekly_lesson_rule_id', [$monday->id, $tuesday->id])->assertJsonPath('data.*.pair_number', [1, 4])->assertJsonPath('meta.start_date', $start)->assertJsonPath('meta.end_date', $end);
        }
    }

    public function test_replacement_is_frozen_for_one_day_and_deletion_restores_base(): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule, ['weekday' => 1, 'room' => 'base']);
        $exception = $this->snapshot($schedule, $rule, '2026-09-07', changes: ['room' => 'replacement']);
        $rule->update(['room' => 'changed base']);
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule, '/week/2026-09-07'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'replacement')->assertJsonPath('data.0.weekly_lesson_rule_id', null)->assertJsonPath('data.0.schedule_date_exception_id', $exception->id)->assertJsonPath('data.0.room', 'replacement');
        $this->getJson($this->url($schedule, '/date/2026-09-14'))->assertOk()->assertJsonPath('data.0.room', 'changed base');
        $exception->lessons()->delete();
        $this->getJson($this->url($schedule, '/date/2026-09-07'))->assertOk()->assertJsonCount(0, 'data');
        $exception->delete();
        $this->getJson($this->url($schedule, '/date/2026-09-07'))->assertOk()->assertJsonPath('data.0.room', 'changed base')->assertJsonPath('data.0.source', 'base')->assertJsonPath('data.0.weekly_lesson_rule_id', $rule->id);
    }
}
