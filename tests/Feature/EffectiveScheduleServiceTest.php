<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\ScheduleDateException;
use App\Models\ScheduleDateExceptionLesson;
use App\Models\ScheduleImportBatch;
use App\Models\SemesterSchedule;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use App\Services\Schedule\EffectiveScheduleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

#[Group('mysql')]
class EffectiveScheduleServiceTest extends TestCase
{
    use AssertsMysqlPersistence, RefreshDatabase;

    private function schedule(?User $owner = null): SemesterSchedule
    {
        $owner ??= User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create([
            'starts_on' => '2026-09-07', 'ends_on' => '2026-09-20',
        ]);

        return $owner->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'ПІ-231',
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator'])->refresh();
    }

    private function rule(SemesterSchedule $schedule, array $changes = []): WeeklyLessonRule
    {
        return $schedule->weeklyLessonRules()->create(array_replace([
            'user_id' => $schedule->user_id, 'weekday' => 2, 'week_pattern' => 'all',
            'pair_number' => 4, 'pair_label' => '7-8', 'starts_at_local' => '13:20:00', 'ends_at_local' => '14:40:00',
            'subject_id' => Subject::factory()->for($schedule->user)->create()->id,
        ], $changes))->refresh();
    }

    private function exception(SemesterSchedule $schedule, string $date = '2026-09-08', string $kind = 'replacement'): ScheduleDateException
    {
        return $schedule->dateExceptions()->create(['user_id' => $schedule->user_id, 'occurs_on' => $date,
            'kind' => $kind, 'copied_weekday' => $kind === 'working_saturday' ? 5 : null])->refresh();
    }

    private function snapshot(ScheduleDateException $exception, WeeklyLessonRule $rule, array $changes = []): ScheduleDateExceptionLesson
    {
        return $exception->lessons()->create(array_replace($rule->only([
            'user_id', 'semester_schedule_id', 'pair_number', 'pair_label', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room',
        ]), ['source_weekly_rule_id' => $rule->id], $changes))->refresh();
    }

    public function test_alternating_tuesdays_and_updated_base_rules_are_read_without_materialization(): void
    {
        $schedule = $this->schedule();
        $numerator = $this->rule($schedule, ['week_pattern' => 'numerator']);
        $denominator = $this->rule($schedule, ['week_pattern' => 'denominator']);
        $service = app(EffectiveScheduleService::class);
        $first = $service->date($schedule->user, $schedule, '2026-09-08');
        $this->assertCount(1, $first);
        $this->assertSame($numerator->subject_id, $first[0]['subject_id']);
        $this->assertSame('2026-09-08T13:20:00+03:00', $first[0]['starts_at']);
        $this->assertSame($denominator->subject_id, $service->date($schedule->user, $schedule, '2026-09-15')[0]['subject_id']);
        $teacher = Teacher::factory()->for($schedule->user)->create();
        $numerator->update(['teacher_id' => $teacher->id]);
        $this->assertSame($teacher->id, $service->date($schedule->user, $schedule, '2026-09-08')[0]['teacher_id']);
        $this->assertSame($first[0]['occurrence_id'], $service->date($schedule->user, $schedule, '2026-09-08')[0]['occurrence_id']);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_date_exception_is_independent_and_deletion_restores_the_base_day(): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule, ['weekday' => 1]);
        $exception = $this->exception($schedule, '2026-09-07');
        $snapshot = $this->snapshot($exception, $rule, ['room' => 'snapshot room']);
        $rule->update(['room' => 'new base room']);
        $service = app(EffectiveScheduleService::class);
        $this->assertSame('snapshot room', $service->date($schedule->user, $schedule, '2026-09-07')[0]['room']);
        $this->assertSame('new base room', $service->date($schedule->user, $schedule, '2026-09-14')[0]['room']);
        $snapshot->delete();
        $this->assertSame([], $service->date($schedule->user, $schedule, '2026-09-07'));
        $exception->delete();
        $this->assertSame('new base room', $service->date($schedule->user, $schedule, '2026-09-07')[0]['room']);
    }

    public function test_working_saturday_is_a_frozen_isolated_snapshot(): void
    {
        $schedule = $this->schedule();
        $friday = $this->rule($schedule, ['weekday' => 5]);
        $exception = $this->exception($schedule, '2026-09-12', 'working_saturday');
        $snapshot = $this->snapshot($exception, $friday, ['room' => 'Saturday']);
        $friday->update(['room' => 'Friday']);
        $service = app(EffectiveScheduleService::class);
        $this->assertSame('Saturday', $service->date($schedule->user, $schedule, '2026-09-12')[0]['room']);
        $this->assertSame('Friday', $service->date($schedule->user, $schedule, '2026-09-11')[0]['room']);
        $this->assertSame([], $service->date($schedule->user, $schedule, '2026-09-19'));
        $exception->delete();
        $this->assertModelMissing($snapshot);
        $this->assertSame([], $service->date($schedule->user, $schedule, '2026-09-12'));
    }

    public function test_reads_use_persisted_timezone_and_do_not_write_or_mutate_input_models(): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule);
        $exception = $this->exception($schedule, '2026-09-12', 'working_saturday');
        $this->snapshot($exception, $rule);
        $owner = $schedule->user;
        $owner->timezone = 'America/New_York';
        // Never trust loaded client-selected relationships either.
        $schedule->setRelation('weeklyLessonRules', collect());
        $tables = ['users', 'academic_periods', 'semester_schedules', 'weekly_lesson_rules', 'schedule_date_exceptions',
            'schedule_date_exception_lessons', 'lessons', 'subjects', 'teachers', 'schedule_import_batches', 'schedule_import_rows'];
        $state = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $state();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service = app(EffectiveScheduleService::class);
        $first = $service->date($owner, $schedule, '2026-09-08');
        $this->assertSame($first, $service->date($owner, $schedule, '2026-09-08'));
        $this->assertSame('working_saturday', $service->date($owner, $schedule, '2026-09-12')[0]['source']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/\Aselect\b/i', $query['query']);
        }
        $this->assertSame('2026-09-08T13:20:00+03:00', $first[0]['starts_at']);
        $this->assertSame($before, $state());
        $this->assertSame('America/New_York', $owner->timezone);
        $this->assertCount(0, $schedule->weeklyLessonRules);
    }

    public function test_foreign_and_missing_schedules_are_hidden_by_owned_query(): void
    {
        $schedule = $this->schedule();
        $this->rule($schedule);
        $other = User::factory()->create();
        $this->assertSame([], $other->semesterSchedules->all());
        $this->expectException(ModelNotFoundException::class);
        app(EffectiveScheduleService::class)->date($other, $schedule, '2026-09-08');
    }

    #[DataProvider('ownerDependencies')]
    public function test_mysql_rejects_foreign_dependency_owners_on_updates(string $target, string $field): void
    {
        $schedule = $this->schedule();
        $other = $this->schedule();
        $rule = $this->rule($schedule);
        $foreignRule = $this->rule($other, ['pair_number' => 5]);
        $exception = $this->exception($schedule);
        $foreignException = $this->exception($other, '2026-09-15');
        $snapshot = $this->snapshot($exception, $rule);
        $teacher = Teacher::factory()->for($other->user)->create();
        $batch = ScheduleImportBatch::factory()->for($other->user)->for($other->academicPeriod)->create();
        $record = ['schedule' => $schedule, 'rule' => $rule, 'exception' => $exception, 'snapshot' => $snapshot][$target];
        // Isolate the child-side owner FK from unrelated parent-side RESTRICTs.
        if ($target !== 'snapshot') {
            $snapshot->delete();
        }
        if ($target === 'schedule') {
            $exception->delete();
            $rule->delete();
        }
        $foreignId = match ($field) {
            'user_id' => $other->user_id,
            'academic_period_id' => $other->academic_period_id,
            'subject_id' => $foreignRule->subject_id,
            'teacher_id' => $teacher->id,
            'source_import_batch_id' => $batch->id,
            'source_weekly_rule_id' => $foreignRule->id,
            'schedule_date_exception_id' => $foreignException->id,
            default => $other->id,
        };
        $this->assertMysqlRejects(fn () => DB::table($record->getTable())->where('id', $record->id)->update([$field => $foreignId]), 1452);
        $this->assertModelExists($record);
    }

    public static function ownerDependencies(): array
    {
        return [
            ['schedule', 'academic_period_id'], ['schedule', 'user_id'],
            ['rule', 'semester_schedule_id'], ['rule', 'user_id'], ['rule', 'subject_id'], ['rule', 'teacher_id'], ['rule', 'source_import_batch_id'],
            ['exception', 'semester_schedule_id'], ['exception', 'user_id'],
            ['snapshot', 'semester_schedule_id'], ['snapshot', 'user_id'], ['snapshot', 'subject_id'], ['snapshot', 'teacher_id'],
            ['snapshot', 'source_weekly_rule_id'], ['snapshot', 'schedule_date_exception_id'],
        ];
    }

    public function test_mysql_prevents_cross_schedule_snapshot_provenance_even_for_same_owner(): void
    {
        $schedule = $this->schedule();
        $other = $this->schedule($schedule->user);
        $rule = $this->rule($schedule);
        $otherRule = $this->rule($other);
        $snapshot = $this->snapshot($this->exception($schedule), $rule);
        $this->assertMysqlRejects(fn () => DB::table($snapshot->getTable())->where('id', $snapshot->id)->update(['source_weekly_rule_id' => $otherRule->id]), 1452);
    }

    public function test_duplicate_period_day_and_pair_keys_are_enforced_on_insert(): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule);
        $exception = $this->exception($schedule);
        $snapshot = $this->snapshot($exception, $rule);
        foreach ([$schedule, $rule, $exception, $snapshot] as $record) {
            $values = $record->getAttributes();
            unset($values['id']);
            $this->assertMysqlRejects(fn () => DB::table($record->getTable())->insert($values), 1062);
        }
    }

    public function test_mysql_rejects_cross_owner_inserts_in_each_new_table(): void
    {
        $schedule = $this->schedule();
        $foreign = User::factory()->create();
        $rule = $this->rule($schedule);
        $exception = $this->exception($schedule);
        $snapshot = $this->snapshot($exception, $rule);
        foreach ([$schedule, $rule, $exception, $snapshot] as $record) {
            $values = $record->getAttributes();
            unset($values['id']);
            $values['user_id'] = $foreign->id;
            if ($record instanceof WeeklyLessonRule || $record instanceof ScheduleDateExceptionLesson) {
                $values['pair_number'] = 5;
            }
            if ($record instanceof ScheduleDateException) {
                $values['occurs_on'] = '2026-09-09';
            }
            $this->assertMysqlRejects(fn () => DB::table($record->getTable())->insert($values), 1452);
        }
    }

    public function test_fk_dependencies_restrict_deletion_until_explicitly_detached(): void
    {
        $schedule = $this->schedule();
        $teacher = Teacher::factory()->for($schedule->user)->create();
        $rule = $this->rule($schedule, ['teacher_id' => $teacher->id]);
        $exception = $this->exception($schedule);
        $this->snapshot($exception, $rule);
        foreach ([$rule, $teacher, $rule->subject, $schedule->academicPeriod] as $record) {
            $this->assertMysqlRejects(fn () => DB::table($record->getTable())->where('id', $record->id)->delete(), 1451);
        }
        // Provenance is nullable: detaching it never changes the saved lesson data.
        $exception->lessons()->update(['source_weekly_rule_id' => null]);
        $rule->delete();
        $this->assertSame($teacher->id, app(EffectiveScheduleService::class)->date($schedule->user, $schedule, '2026-09-08')[0]['teacher_id']);
    }

    #[DataProvider('invalidScalars')]
    public function test_mysql_enforces_scalar_constraints(string $target, array $changes): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule);
        $exception = $this->exception($schedule);
        $snapshot = $this->snapshot($exception, $rule);
        $record = ['schedule' => $schedule, 'rule' => $rule, 'exception' => $exception, 'snapshot' => $snapshot][$target];
        $this->assertMysqlRejects(fn () => DB::table($record->getTable())->where('id', $record->id)->update($changes), 3819);
    }

    public static function invalidScalars(): array
    {
        return [
            ['schedule', ['first_week_parity' => 'all']], ['schedule', ['parity_anchor_on' => '2026-09-08']],
            ['rule', ['weekday' => 6]], ['rule', ['week_pattern' => 'odd']], ['rule', ['pair_number' => 0]],
            ['rule', ['starts_at_local' => '-01:00:00']], ['rule', ['ends_at_local' => '13:20:00']], ['rule', ['ends_at_local' => '24:00:00']],
            ['snapshot', ['pair_number' => 0]], ['snapshot', ['ends_at_local' => '12:00:00']],
            ['exception', ['kind' => 'other']], ['exception', ['kind' => 'working_saturday']],
            ['exception', ['copied_weekday' => 5]],
        ];
    }

    public function test_service_rejects_conflicts_that_database_keys_cannot_express(): void
    {
        $schedule = $this->schedule();
        $this->rule($schedule);
        $this->rule($schedule, ['week_pattern' => 'numerator']);
        $this->expectException(InvalidArgumentException::class);
        app(EffectiveScheduleService::class)->date($schedule->user, $schedule, '2026-09-07');
    }

    public function test_missing_institution_is_rejected_without_repairing_legacy_period(): void
    {
        $schedule = $this->schedule();
        $schedule->academicPeriod->update(['education_institution_id' => null]);
        $this->expectException(InvalidArgumentException::class);
        app(EffectiveScheduleService::class)->date($schedule->user, $schedule, '2026-09-08');
    }

    public function test_malformed_foreign_legacy_institution_is_rejected(): void
    {
        $schedule = $this->schedule();
        $foreign = EducationInstitution::factory()->create();
        DB::table('academic_periods')->where('id', $schedule->academic_period_id)->update(['education_institution_id' => $foreign->id]);
        $this->expectException(InvalidArgumentException::class);
        app(EffectiveScheduleService::class)->date($schedule->user, $schedule, '2026-09-08');
    }

    public function test_same_owner_import_from_another_period_is_rejected(): void
    {
        $schedule = $this->schedule();
        $batch = ScheduleImportBatch::factory()->for($schedule->user)->create();
        $this->rule($schedule, ['source_import_batch_id' => $batch->id]);
        $this->expectException(InvalidArgumentException::class);
        app(EffectiveScheduleService::class)->date($schedule->user, $schedule, '2026-09-08');
    }

    public function test_owned_import_provenance_relationship_uses_the_source_foreign_key(): void
    {
        $schedule = $this->schedule();
        $batch = ScheduleImportBatch::factory()->for($schedule->user)->for($schedule->academicPeriod)->create();
        $rule = $this->rule($schedule, ['source_import_batch_id' => $batch->id]);
        $this->assertSame([$rule->id], $batch->weeklyLessonRules->modelKeys());
        $this->assertTrue($rule->sourceImportBatch->is($batch));
        $this->assertSame($rule->id, app(EffectiveScheduleService::class)->date($schedule->user, $schedule, '2026-09-08')[0]['weekly_lesson_rule_id']);
    }

    public function test_owned_dates_outside_semester_are_empty_and_malformed_date_is_rejected(): void
    {
        $schedule = $this->schedule();
        $this->rule($schedule);
        $service = app(EffectiveScheduleService::class);
        $this->assertSame([], $service->date($schedule->user, $schedule, '2026-09-01'));
        $this->assertSame([], $service->date($schedule->user, $schedule, '2026-09-22'));
        $this->expectException(InvalidArgumentException::class);
        $service->date($schedule->user, $schedule, '2026-02-30');
    }

    public function test_kyiv_dst_gap_is_not_silently_shifted_by_eloquent_or_php(): void
    {
        $schedule = $this->schedule();
        $schedule->academicPeriod->update(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $rule = $this->rule($schedule);
        $this->snapshot($this->exception($schedule, '2026-03-29'), $rule, ['starts_at_local' => '03:30:00', 'ends_at_local' => '05:00:00']);
        $this->expectException(InvalidArgumentException::class);
        app(EffectiveScheduleService::class)->date($schedule->user, $schedule, '2026-03-29');
    }
}
