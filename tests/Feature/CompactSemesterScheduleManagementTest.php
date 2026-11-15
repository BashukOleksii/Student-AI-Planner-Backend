<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\ScheduleDateException;
use App\Models\ScheduleDateExceptionLesson;
use App\Models\SemesterSchedule;
use App\Models\SemesterScheduleSuppressedSlot;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsBatchedReads;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactSemesterScheduleManagementTest extends TestCase
{
    use AssertsBatchedReads;
    use RefreshDatabase;

    private User $owner;

    private AcademicPeriod $period;

    private Subject $subject;

    private Teacher $teacher;

    private int $scheduleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($this->owner)->create();
        $this->period = AcademicPeriod::factory()->for($this->owner)->for($institution)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);
        $this->subject = Subject::factory()->for($this->owner)->create();
        $this->teacher = Teacher::factory()->for($this->owner)->create();
        $this->actingAs($this->owner, 'web');
        $this->scheduleId = $this->postJson('/api/semester-schedules', $this->scheduleFields())->assertCreated()->json('data.schedule.id');
    }

    protected function tearDown(): void
    {
        foreach ([WeeklyLessonRule::class, ScheduleDateException::class, ScheduleDateExceptionLesson::class, SemesterScheduleSuppressedSlot::class, SemesterSchedule::class] as $model) {
            $model::flushEventListeners();
        }
        parent::tearDown();
    }

    private function scheduleFields(array $changes = []): array
    {
        return array_replace(['academic_period_id' => $this->period->id, 'group_name' => 'ПІ-231', 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator'], $changes);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/semester-schedules/'.$this->scheduleId.$suffix;
    }

    private function state(): TestResponse
    {
        return $this->getJson($this->url('/management'))->assertOk();
    }

    private function mutate(string $method, string $suffix, array $values = []): TestResponse
    {
        return $this->json($method, $this->url($suffix), [...$values, 'schedule_token' => $this->state()->json('meta.schedule_token')]);
    }

    private function lesson(array $changes = []): array
    {
        return array_replace(['pair_number' => 1, 'pair_label' => '1-2', 'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00', 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id, 'room' => '101'], $changes);
    }

    private function rule(array $changes = []): int
    {
        $response = $this->mutate('POST', '/weekly-rules', ['weekday' => 1, 'week_pattern' => 'all', ...$this->lesson(), ...$changes])->assertCreated();
        $rows = $response->json('data.weekly_rules');

        return end($rows)['id'];
    }

    public function test_manual_creation_detail_uniqueness_and_empty_safe_deletion(): void
    {
        $this->assertDatabaseCount('semester_schedules', 1);
        $this->assertDatabaseCount('lessons', 0);
        $this->state()->assertJsonCount(0, 'data.weekly_rules')->assertJsonPath('data.schedule.group_name', 'ПІ-231');
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.academic_period.id', $this->period->id)->assertJsonMissingPath('data.schedule_token');
        $this->postJson('/api/semester-schedules', $this->scheduleFields())->assertUnprocessable()->assertJsonValidationErrors('academic_period_id');
        $this->mutate('PATCH', '', ['group_name' => 'Changed'])->assertOk()->assertJsonPath('data.schedule.group_name', 'Changed');
        $this->mutate('PATCH', '', ['academic_period_id' => $this->period->id])->assertUnprocessable();
        $this->mutate('DELETE', '', ['confirmed' => true])->assertOk();
        $this->getJson($this->url())->assertNotFound();
    }

    public function test_rules_propagate_on_normal_dates_and_keep_ids_across_identity_changes(): void
    {
        $id = $this->rule();
        foreach (['2026-09-07', '2026-09-14'] as $date) {
            $this->getJson($this->url('/date/'.$date))->assertOk()->assertJsonPath('data.0.weekly_lesson_rule_id', $id)->assertJsonPath('data.0.room', '101');
        }
        $this->mutate('PATCH', '/weekly-rules/'.$id, ['teacher_id' => null])->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $teacher = $this->teacher;
        $conflictingTeacher = Teacher::factory()->for($this->owner)->create();
        $this->mutate('PATCH', '/weekly-rules/'.$id, ['teacher_id' => $conflictingTeacher->id])->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->mutate('PATCH', '/weekly-rules/'.$id, ['room' => '202', 'teacher_id' => $teacher->id])->assertOk();
        foreach (['2026-09-07', '2026-09-14'] as $date) {
            $this->getJson($this->url('/date/'.$date))->assertOk()->assertJsonPath('data.0.room', '202')->assertJsonPath('data.0.teacher.id', $teacher->id);
        }
        $this->mutate('PATCH', '/weekly-rules/'.$id, ['weekday' => 2, 'pair_number' => 4, 'pair_label' => '7-8'])->assertOk();
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonCount(0, 'data');
        $this->getJson($this->url('/date/2026-09-08'))->assertJsonPath('data.0.weekly_lesson_rule_id', $id)->assertJsonPath('data.0.pair_number', 4);
        $this->getJson($this->url('/weekly-rules/'.$id))->assertOk()->assertJsonPath('data.id', $id);
        $this->mutate('DELETE', '/weekly-rules/'.$id, ['confirmed' => true])->assertOk()->assertJsonCount(0, 'data.weekly_rules')->assertJsonCount(1, 'data.suppressed_slots');
        $this->getJson($this->url('/weekly-rules/'.$id))->assertNotFound();
        $this->getJson($this->url('/week/2026-09-07'))->assertJsonCount(0, 'data');
    }

    public function test_alternating_rules_and_anchor_updates_use_shared_calculator(): void
    {
        $num = $this->rule(['weekday' => 2, 'week_pattern' => 'numerator']);
        $den = $this->rule(['weekday' => 2, 'week_pattern' => 'denominator', 'room' => 'DEN']);
        $this->getJson($this->url('/date/2026-09-08'))->assertJsonPath('data.0.weekly_lesson_rule_id', $num);
        $this->getJson($this->url('/date/2026-09-15'))->assertJsonPath('data.0.weekly_lesson_rule_id', $den);
        $this->mutate('PATCH', '', ['first_week_parity' => 'denominator'])->assertOk();
        $this->getJson($this->url('/date/2026-09-08'))->assertJsonPath('data.0.weekly_lesson_rule_id', $den);
        $this->mutate('PATCH', '', ['parity_anchor_on' => '2026-08-31'])->assertOk();
        $this->getJson($this->url('/date/2026-09-08'))->assertJsonPath('data.0.weekly_lesson_rule_id', $num);
    }

    #[DataProvider('badRuleFields')]
    public function test_invalid_rule_fields_are_controlled_without_writes(array $changes): void
    {
        $before = $this->state()->json('meta.schedule_token');
        $this->mutate('POST', '/weekly-rules', ['weekday' => 1, 'week_pattern' => 'all', ...$this->lesson(), ...$changes])->assertUnprocessable();
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
        $this->assertSame($before, $this->state()->json('meta.schedule_token'));
    }

    public static function badRuleFields(): array
    {
        return [0 => [['weekday' => 6]]];
    }

    public function test_conflicts_reject_duplicate_effective_pairs_and_overlaps_but_accept_adjacency(): void
    {
        $id = $this->rule();
        foreach ([['week_pattern' => 'numerator'], ['pair_number' => 2], ['pair_number' => 2, 'starts_at_local' => '09:49:59', 'ends_at_local' => '10:30:00']] as $changes) {
            $this->mutate('POST', '/weekly-rules', ['weekday' => 1, 'week_pattern' => 'all', ...$this->lesson(), ...$changes])->assertUnprocessable()->assertJsonValidationErrors('schedule');
        }
        $second = $this->rule(['pair_number' => 2, 'starts_at_local' => '09:50:00', 'ends_at_local' => '11:00:00']);
        $this->mutate('PATCH', '/weekly-rules/'.$second, ['pair_number' => 1])->assertUnprocessable();
        $this->getJson($this->url('/weekly-rules'))->assertOk()->assertJsonPath('data.*.id', [$id, $second]);
    }

    public function test_single_pair_copy_changes_preserve_others_and_freeze_full_day(): void
    {
        $id = $this->rule();
        $second = $this->rule(['pair_number' => 2, 'starts_at_local' => '10:00:00', 'ends_at_local' => '11:20:00']);
        $subject = Subject::factory()->for($this->owner)->create();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base', 'changes' => [['weekly_lesson_rule_id' => $id, 'subject_id' => $subject->id, 'teacher_id' => $this->teacher->id, 'room' => 'replacement']]])->assertOk()->assertJsonCount(2, 'data.date_exceptions.0.lessons');
        $snapshot = $this->getJson($this->url('/date-exceptions/2026-09-07'))->assertOk();
        $snapshot->assertJsonPath('data.lessons.0.subject.id', $subject->id)->assertJsonPath('data.lessons.1.source_weekly_rule_id', $second);
        $this->mutate('PATCH', '/weekly-rules/'.$second, ['room' => 'new base'])->assertOk();
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonPath('data.*.room', ['replacement', '101']);
        $this->getJson($this->url('/date/2026-09-14'))->assertJsonPath('data.*.room', ['101', 'new base']);
        $this->mutate('DELETE', '/date-exceptions/2026-09-07', ['confirmed' => true])->assertOk();
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonPath('data.*.room', ['101', 'new base'])->assertJsonPath('data.0.source', 'base');
    }
}
