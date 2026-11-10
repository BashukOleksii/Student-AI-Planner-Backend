<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\ScheduleDateException;
use App\Models\ScheduleDateExceptionLesson;
use App\Models\SemesterSchedule;
use App\Models\SemesterScheduleSuppressedSlot;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SemesterScheduleManagementTest extends TestCase
{
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
        return array_replace(['academic_period_id' => $this->period->id, 'group_name' => 'ПІ-231',
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator'], $changes);
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
        return array_replace(['pair_number' => 1, 'pair_label' => '1-2', 'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00',
            'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id, 'room' => '101'], $changes);
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
        $this->mutate('PATCH', '/weekly-rules/'.$id, ['room' => '202', 'teacher_id' => null])->assertOk();
        foreach (['2026-09-07', '2026-09-14'] as $date) {
            $this->getJson($this->url('/date/'.$date))->assertOk()->assertJsonPath('data.0.room', '202')->assertJsonPath('data.0.teacher', null);
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
        return [[['weekday' => 6]], [['weekday' => '1']], [['pair_number' => 0]], [['pair_number' => 256]],
            [['week_pattern' => 'odd']], [['starts_at_local' => '09:50:00']], [['ends_at_local' => '24:00:00']],
            [['starts_at_local' => '08:30']], [['subject_id' => 999999]], [['teacher_id' => 999999]], [['room' => str_repeat('r', 101)]]];
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
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base',
            'changes' => [['weekly_lesson_rule_id' => $id, 'subject_id' => $subject->id, 'teacher_id' => null, 'room' => 'replacement']]])
            ->assertOk()->assertJsonCount(2, 'data.date_exceptions.0.lessons');
        $snapshot = $this->getJson($this->url('/date-exceptions/2026-09-07'))->assertOk();
        $snapshot->assertJsonPath('data.lessons.0.subject.id', $subject->id)->assertJsonPath('data.lessons.1.source_weekly_rule_id', $second);
        $this->mutate('PATCH', '/weekly-rules/'.$second, ['room' => 'new base'])->assertOk();
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonPath('data.*.room', ['replacement', '101']);
        $this->getJson($this->url('/date/2026-09-14'))->assertJsonPath('data.*.room', ['101', 'new base']);
        $this->mutate('DELETE', '/date-exceptions/2026-09-07', ['confirmed' => true])->assertOk();
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonPath('data.*.room', ['101', 'new base'])->assertJsonPath('data.0.source', 'base');
    }

    public function test_copy_can_cancel_one_pair_add_extra_and_empty_snapshot_cancels_all(): void
    {
        $id = $this->rule();
        $this->rule(['pair_number' => 2, 'starts_at_local' => '10:00:00', 'ends_at_local' => '11:20:00']);
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base',
            'changes' => [['weekly_lesson_rule_id' => $id, 'cancelled' => true]],
            'lessons' => [$this->lesson(['pair_number' => 3, 'starts_at_local' => '12:00:00', 'ends_at_local' => '13:00:00'])]])->assertOk();
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonPath('data.*.pair_number', [2, 3]);
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => []])->assertOk();
        $this->getJson($this->url('/date-exceptions/2026-09-07'))->assertOk()->assertJsonPath('data.lessons', []);
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonCount(0, 'data');
        $this->getJson($this->url('/date/2026-09-14'))->assertJsonCount(2, 'data');
        $this->getJson($this->url('/week/2026-09-07'))->assertJsonCount(0, 'data');
    }

    public function test_snapshot_full_replace_can_swap_pairs_while_retaining_owned_row_ids(): void
    {
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => [$this->lesson(),
            $this->lesson(['pair_number' => 2, 'starts_at_local' => '10:00:00', 'ends_at_local' => '11:20:00'])]])->assertOk();
        $rows = $this->getJson($this->url('/date-exceptions/2026-09-07'))->json('data.lessons');
        $before = DB::table('schedule_date_exception_lessons')->orderBy('id')->pluck('created_at', 'id')->toArray();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => [
            ['id' => $rows[0]['id'], ...$this->lesson(['pair_number' => 2])],
            ['id' => $rows[1]['id'], ...$this->lesson(['pair_number' => 1, 'starts_at_local' => '10:00:00', 'ends_at_local' => '11:20:00'])],
        ]])->assertOk();
        $this->assertSame($before, DB::table('schedule_date_exception_lessons')->orderBy('id')->pluck('created_at', 'id')->toArray());
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonPath('data.*.pair_number', [2, 1]);
    }

    public function test_working_saturday_copies_effective_source_replacement_and_remains_independent(): void
    {
        $this->rule(['weekday' => 5]);
        $this->mutate('PUT', '/date-exceptions/2026-09-11', ['mode' => 'copy_base', 'changes' => [['weekly_lesson_rule_id' => WeeklyLessonRule::first()->id, 'room' => 'Friday override']]])->assertOk();
        $this->mutate('POST', '/working-saturdays', ['occurs_on' => '2026-09-12', 'copied_weekday' => 5])->assertCreated();
        $this->getJson($this->url('/date/2026-09-12'))->assertJsonPath('data.0.source', 'working_saturday')->assertJsonPath('data.0.room', 'Friday override');
        $this->mutate('PUT', '/date-exceptions/2026-09-11', ['mode' => 'replace', 'lessons' => []])->assertOk();
        $this->mutate('PUT', '/date-exceptions/2026-09-12', ['mode' => 'replace', 'lessons' => [$this->lesson(['room' => 'Saturday only'])]])->assertOk();
        $this->getJson($this->url('/date/2026-09-12'))->assertJsonPath('data.0.room', 'Saturday only')->assertJsonPath('data.0.source', 'working_saturday');
        $this->getJson($this->url('/date/2026-09-11'))->assertJsonCount(0, 'data');
        $this->getJson($this->url('/date/2026-09-19'))->assertJsonCount(0, 'data');
        $this->mutate('POST', '/working-saturdays', ['occurs_on' => '2026-09-12', 'copied_weekday' => 5])->assertConflict();
        $this->mutate('PUT', '/date-exceptions/2026-09-12', ['mode' => 'copy_base'])->assertUnprocessable();
        $this->mutate('DELETE', '/date-exceptions/2026-09-12', ['confirmed' => true])->assertOk();
        $this->getJson($this->url('/date/2026-09-12'))->assertJsonCount(0, 'data');
    }

    public function test_saturday_uses_source_parity_and_can_copy_an_empty_cancelled_day(): void
    {
        $num = $this->rule(['weekday' => 5, 'week_pattern' => 'numerator']);
        $den = $this->rule(['weekday' => 5, 'week_pattern' => 'denominator', 'room' => 'DEN']);
        $this->mutate('POST', '/working-saturdays', ['occurs_on' => '2026-09-19', 'copied_weekday' => 5])->assertCreated();
        $this->getJson($this->url('/date-exceptions/2026-09-19'))->assertJsonPath('data.lessons.0.source_weekly_rule_id', $den);
        $this->mutate('PUT', '/date-exceptions/2026-09-11', ['mode' => 'replace', 'lessons' => []])->assertOk();
        $this->mutate('POST', '/working-saturdays', ['occurs_on' => '2026-09-12', 'copied_weekday' => 5])->assertCreated();
        $this->getJson($this->url('/date-exceptions/2026-09-12'))->assertJsonPath('data.lessons', []);
        $this->assertModelExists(WeeklyLessonRule::find($num));
    }

    #[DataProvider('invalidSaturday')]
    public function test_invalid_saturday_selection_has_no_partial_snapshot(string $date, mixed $weekday): void
    {
        $this->mutate('POST', '/working-saturdays', ['occurs_on' => $date, 'copied_weekday' => $weekday])->assertUnprocessable();
        $this->assertDatabaseCount('schedule_date_exceptions', 0);
    }

    public static function invalidSaturday(): array
    {
        return [['2026-09-11', 5], ['2026-09-12', 0], ['2026-09-12', 6], ['2026-09-12', '5'], ['2026-09-26', 5], ['2026-02-30', 5]];
    }

    public function test_dates_within_period_and_valid_owned_snapshot_references_are_required(): void
    {
        $rule = $this->rule(['weekday' => 2]);
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base', 'changes' => [['weekly_lesson_rule_id' => $rule, 'room' => 'wrong day']]])->assertUnprocessable();
        $this->mutate('PUT', '/date-exceptions/2026-09-06', ['mode' => 'replace', 'lessons' => []])->assertUnprocessable();
        $this->mutate('PUT', '/date-exceptions/invalid?date=2026-09-07', ['mode' => 'replace', 'lessons' => []])->assertUnprocessable();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => [['id' => 99999, ...$this->lesson()]]])->assertUnprocessable();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace'])->assertUnprocessable();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => [$this->lesson(), $this->lesson()]])->assertUnprocessable();
        $this->assertDatabaseCount('schedule_date_exceptions', 0);
    }

    public function test_deleting_a_source_rule_detaches_snapshot_without_losing_frozen_data(): void
    {
        $rule = $this->rule();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base'])->assertOk();
        $before = ScheduleDateExceptionLesson::first()->getAttributes();
        $this->mutate('DELETE', '/weekly-rules/'.$rule, ['confirmed' => true])->assertOk();
        $after = ScheduleDateExceptionLesson::first()->getAttributes();
        unset($before['updated_at'], $after['updated_at']);
        $before['source_weekly_rule_id'] = null;
        $this->assertSame($before, $after);
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'replacement');
        $this->getJson($this->url('/date/2026-09-14'))->assertJsonCount(0, 'data');
        $this->mutate('POST', '/weekly-rules', ['weekday' => 1, 'week_pattern' => 'all', ...$this->lesson()])->assertUnprocessable()->assertJsonValidationErrors('suppressed_slot');
        $this->mutate('DELETE', '', ['confirmed' => true])->assertUnprocessable();
        $slot = $this->state()->json('data.suppressed_slots.0.id');
        $this->mutate('DELETE', '/suppressed-slots/'.$slot, ['confirmed' => true])->assertOk();
        $this->rule();
    }

    public function test_lost_updates_and_catalog_timezone_changes_require_a_fresh_token(): void
    {
        $id = $this->rule();
        $token = $this->state()->json('meta.schedule_token');
        $this->patchJson($this->url('/weekly-rules/'.$id), ['schedule_token' => $token, 'room' => 'first'])->assertOk();
        $this->patchJson($this->url('/weekly-rules/'.$id), ['schedule_token' => $token, 'room' => 'stale'])->assertConflict();
        $token = $this->state()->json('meta.schedule_token');
        $this->subject->update(['code' => 'changed']);
        $this->deleteJson($this->url('/weekly-rules/'.$id), ['schedule_token' => $token, 'confirmed' => true])->assertConflict();
        $token = $this->state()->json('meta.schedule_token');
        $this->owner->update(['timezone' => 'UTC']);
        $this->patchJson($this->url(), ['schedule_token' => $token, 'group_name' => 'stale'])->assertConflict();
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonPath('data.0.room', 'first');
    }

    public function test_noop_patches_preserve_state_but_rapid_edit_then_revert_does_not_revive_tokens(): void
    {
        $id = $this->rule();
        $before = $this->state()->json();
        $revision = SemesterSchedule::find($this->scheduleId)->management_revision;
        $this->mutate('PATCH', '', ['group_name' => 'ПІ-231'])->assertOk();
        $this->mutate('PATCH', '/weekly-rules/'.$id, ['room' => '101'])->assertOk();
        $this->assertSame($before, $this->state()->json());
        $this->assertSame($revision, SemesterSchedule::find($this->scheduleId)->management_revision);
        $this->travelTo(now()->startOfSecond());
        $this->mutate('PATCH', '', ['group_name' => 'temporary'])->assertOk();
        $this->mutate('PATCH', '', ['group_name' => 'ПІ-231'])->assertOk();
        $this->assertSame($revision + 2, SemesterSchedule::find($this->scheduleId)->management_revision);
        $this->assertNotSame($before['meta']['schedule_token'], $this->state()->json('meta.schedule_token'));
        $this->patchJson($this->url('/weekly-rules/'.$id), ['schedule_token' => $before['meta']['schedule_token'], 'room' => 'stale'])->assertConflict();
        $this->travelBack();
    }

    public function test_dependency_deletion_and_strict_confirmation_are_explicit(): void
    {
        $id = $this->rule();
        $this->mutate('DELETE', '', ['confirmed' => true])->assertUnprocessable();
        foreach ([[], ['confirmed' => 'true'], ['confirmed' => 1], ['confirmed' => false]] as $values) {
            $this->mutate('DELETE', '/weekly-rules/'.$id, $values)->assertUnprocessable();
        }
        $this->assertDatabaseCount('weekly_lesson_rules', 1);
        $this->mutate('DELETE', '/weekly-rules/'.$id, ['confirmed' => true])->assertOk();
        $this->mutate('DELETE', '', ['confirmed' => true])->assertUnprocessable();
        $slot = $this->state()->json('data.suppressed_slots.0.id');
        $this->mutate('DELETE', '/suppressed-slots/'.$slot, ['confirmed' => true])->assertOk();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => []])->assertOk();
        $this->mutate('DELETE', '', ['confirmed' => true])->assertUnprocessable();
        $this->mutate('DELETE', '/date-exceptions/2026-09-07', ['confirmed' => true])->assertOk();
        $this->mutate('DELETE', '', ['confirmed' => true])->assertOk();
    }

    public function test_foreign_dependencies_and_nested_resources_are_not_usable(): void
    {
        $subject = Subject::factory()->create();
        $teacher = Teacher::factory()->create();
        $foreignPeriod = AcademicPeriod::factory()->create();
        $this->postJson('/api/semester-schedules', $this->scheduleFields(['academic_period_id' => $foreignPeriod->id]))->assertUnprocessable();
        foreach ([['subject_id' => $subject->id], ['teacher_id' => $teacher->id]] as $changes) {
            $this->mutate('POST', '/weekly-rules', ['weekday' => 1, 'week_pattern' => 'all', ...$this->lesson($changes)])->assertUnprocessable();
            $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => [$this->lesson($changes)]])->assertUnprocessable();
        }
        $id = $this->rule();
        $period = AcademicPeriod::factory()->for($this->owner)->for($this->period->educationInstitution)->create();
        $other = $this->postJson('/api/semester-schedules', $this->scheduleFields(['academic_period_id' => $period->id]))->json('data.schedule.id');
        $this->patchJson('/api/semester-schedules/'.$other.'/weekly-rules/'.$id, [])->assertNotFound();
        $this->getJson('/api/semester-schedules/'.$other.'/weekly-rules/'.$id)->assertNotFound();
        $this->period->update(['education_institution_id' => null]);
        $this->postJson('/api/semester-schedules', $this->scheduleFields())->assertUnprocessable();
    }

    #[DataProvider('allPaths')]
    public function test_guest_and_foreign_owner_are_denied_before_payload_validation(string $method, string $suffix): void
    {
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->json($method, $suffix === 'store' ? '/api/semester-schedules' : $this->url($suffix), [])->assertUnauthorized();
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        if ($suffix !== 'store') {
            $this->json($method, $this->url($suffix), [])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        }
    }

    public static function allPaths(): array
    {
        return [['POST', 'store'], ['PATCH', ''], ['DELETE', ''], ['GET', '/management'], ['GET', '/weekly-rules'],
            ['POST', '/weekly-rules'], ['GET', '/weekly-rules/9999'], ['PATCH', '/weekly-rules/9999'], ['DELETE', '/weekly-rules/9999'],
            ['GET', '/date-exceptions'], ['GET', '/date-exceptions/invalid'], ['PUT', '/date-exceptions/invalid'], ['DELETE', '/date-exceptions/invalid'],
            ['POST', '/working-saturdays'], ['GET', '/suppressed-slots'], ['DELETE', '/suppressed-slots/9999']];
    }

    public function test_snapshot_rewrite_and_source_deletion_failures_roll_back_every_change(): void
    {
        $id = $this->rule();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base'])->assertOk();
        $before = $this->state()->json();
        ScheduleDateExceptionLesson::creating(fn () => throw new RuntimeException('PRIVATE FAILURE'));
        config(['app.debug' => true]);
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => [$this->lesson()]])
            ->assertStatus(500)->assertExactJson(['message' => 'The semester schedule could not be processed.']);
        $this->assertSame($before, $this->state()->json());
        ScheduleDateExceptionLesson::flushEventListeners();
        WeeklyLessonRule::deleting(fn () => false);
        $this->mutate('DELETE', '/weekly-rules/'.$id, ['confirmed' => true])->assertStatus(500);
        $this->assertSame($before, $this->state()->json());
        $this->assertDatabaseCount('semester_schedule_suppressed_slots', 0);
    }

    public function test_dst_snapshots_validate_target_local_endpoints_and_full_projected_semester(): void
    {
        $this->period->update(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        foreach (['2026-03-29', '2026-10-25'] as $date) {
            $this->mutate('PUT', '/date-exceptions/'.$date, ['mode' => 'replace', 'lessons' => [
                $this->lesson(['starts_at_local' => '03:30:00', 'ends_at_local' => '05:00:00']),
            ]])->assertUnprocessable()->assertJsonValidationErrors('schedule');
        }
        $this->assertDatabaseCount('schedule_date_exceptions', 0);
        $this->mutate('PUT', '/date-exceptions/2026-03-29', ['mode' => 'replace', 'lessons' => [
            $this->lesson(['starts_at_local' => '02:30:00', 'ends_at_local' => '04:30:00']),
        ]])->assertOk();
        $this->getJson($this->url('/date/2026-03-29'))->assertOk()
            ->assertJsonPath('data.0.starts_at', '2026-03-29T02:30:00+02:00')->assertJsonPath('data.0.ends_at', '2026-03-29T04:30:00+03:00');
        // A profile change can invalidate a saved snapshot; management definitions
        // remain readable with a token, and only a valid full repair may commit.
        $this->owner->update(['timezone' => 'UTC']);
        $this->mutate('PUT', '/date-exceptions/2026-03-29', ['mode' => 'replace', 'lessons' => [
            $this->lesson(['starts_at_local' => '03:30:00', 'ends_at_local' => '05:00:00']),
        ]])->assertOk();
        $this->owner->update(['timezone' => 'Europe/Kyiv']);
        $this->getJson($this->url('/date/2026-03-29'))->assertConflict();
        $this->mutate('PATCH', '', ['group_name' => 'invalid graph'])->assertUnprocessable();
        $this->mutate('PUT', '/date-exceptions/2026-03-29', ['mode' => 'replace', 'lessons' => [
            $this->lesson(['starts_at_local' => '02:30:00', 'ends_at_local' => '04:30:00']),
        ]])->assertOk();
        $this->getJson($this->url('/date/2026-03-29'))->assertOk();
    }

    public function test_working_saturday_source_must_be_inside_period_and_all_weekdays_are_supported(): void
    {
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $id = $this->rule(['weekday' => $weekday]);
            $this->mutate('POST', '/working-saturdays', ['occurs_on' => '2026-09-12', 'copied_weekday' => $weekday])->assertCreated();
            $this->getJson($this->url('/date-exceptions/2026-09-12'))->assertJsonPath('data.lessons.0.source_weekly_rule_id', $id);
            $this->mutate('DELETE', '/date-exceptions/2026-09-12', ['confirmed' => true])->assertOk();
        }
        $this->period->update(['starts_on' => '2026-09-12']);
        $this->mutate('POST', '/working-saturdays', ['occurs_on' => '2026-09-12', 'copied_weekday' => 5])->assertUnprocessable();
        $this->assertDatabaseCount('schedule_date_exceptions', 0);
    }

    public function test_invalid_manual_semester_options_and_institutions_are_rejected(): void
    {
        foreach ([['parity_anchor_on' => '2026-09-08'], ['parity_anchor_on' => '2026-02-30'], ['group_name' => ''], ['first_week_parity' => 'odd']] as $changes) {
            $this->mutate('PATCH', '', $changes)->assertUnprocessable();
        }
        $foreign = EducationInstitution::factory()->create();
        $this->period->update(['education_institution_id' => $foreign->id]);
        $this->postJson('/api/semester-schedules', $this->scheduleFields())->assertUnprocessable()->assertJsonValidationErrors('academic_period_id');
    }

    public function test_management_graph_is_batched_and_trusted_profile_controls_timezone(): void
    {
        $this->rule();
        $queries = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson($this->url('/management').'?timezone=UTC&user_id=99999')->assertOk()->assertJsonPath('meta.timezone', 'Europe/Kyiv');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $baseline = $queries();
        foreach ([2, 3, 4, 5] as $weekday) {
            $this->rule(['weekday' => $weekday]);
        }
        foreach (['2026-09-07', '2026-09-08', '2026-09-09'] as $date) {
            $this->mutate('PUT', '/date-exceptions/'.$date, ['mode' => 'copy_base'])->assertOk();
        }
        $dense = $queries();
        $this->assertSame($baseline, $dense);
        $this->assertLessThanOrEqual(14, $dense);
        $owner = $this->owner;
        $owner->timezone = 'UTC';
        $this->actingAs($owner, 'web');
        $this->getJson($this->url('/management'))->assertJsonPath('meta.timezone', 'Europe/Kyiv');
    }

    public function test_whole_semester_anchor_preflight_includes_late_timezone_transitions(): void
    {
        $this->period->update(['starts_on' => '2011-01-01', 'ends_on' => '2011-12-31']);
        $this->owner->update(['timezone' => 'Pacific/Apia']);
        $this->mutate('PATCH', '', ['parity_anchor_on' => '2011-01-03'])->assertOk();
        // Friday 2011-12-30 was skipped. This parity avoids that date until
        // changing the anchor parity, months beyond the representative cycles.
        $id = $this->rule(['weekday' => 5, 'week_pattern' => 'numerator']);
        $before = $this->state()->json();
        $this->mutate('PATCH', '', ['first_week_parity' => 'denominator'])->assertUnprocessable();
        $this->assertSame($before, $this->state()->json());
        $this->assertModelExists(WeeklyLessonRule::find($id));
    }

    public function test_management_gets_are_select_only_and_legacy_schedule_is_independent(): void
    {
        $id = $this->rule();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base'])->assertOk();
        $legacy = Lesson::factory()->for($this->owner)->create(['starts_at' => '2026-09-07 05:30:00', 'ends_at' => '2026-09-07 06:50:00']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (['/management', '/weekly-rules', '/weekly-rules/'.$id, '/date-exceptions', '/date-exceptions/2026-09-07', '/suppressed-slots'] as $path) {
            $this->getJson($this->url($path))->assertOk();
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/\Aselect\b/i', $query['query']);
        }
        $this->getJson('/api/schedule/date/2026-09-07')->assertOk()->assertJsonPath('data.0.id', $legacy->id);
        $this->getJson($this->url('/date/2026-09-07'))->assertJsonMissingPath('data.0.id')->assertJsonPath('data.0.source', 'replacement');
    }
}
