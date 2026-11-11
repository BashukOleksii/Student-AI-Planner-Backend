<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\ScheduleDateException;
use App\Models\SemesterSchedule;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use App\Services\Schedule\EffectiveScheduleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

class SemesterScheduleReadApiTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(?User $user = null): SemesterSchedule
    {
        $user ??= User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($user)->create();
        $period = AcademicPeriod::factory()->for($user)->for($institution)->create([
            'starts_on' => '2026-09-07', 'ends_on' => '2026-09-20',
        ]);

        return $user->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'ПІ-231',
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

    private function snapshot(SemesterSchedule $schedule, WeeklyLessonRule $rule, string $date, string $kind = 'replacement', array $changes = []): ScheduleDateException
    {
        $exception = $schedule->dateExceptions()->create(['user_id' => $schedule->user_id, 'occurs_on' => $date,
            'kind' => $kind, 'copied_weekday' => $kind === 'working_saturday' ? 5 : null]);
        $exception->lessons()->create(array_replace($rule->only([
            'user_id', 'semester_schedule_id', 'pair_number', 'pair_label', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room',
        ]), ['source_weekly_rule_id' => $rule->id], $changes));

        return $exception;
    }

    private function url(SemesterSchedule $schedule, string $suffix = ''): string
    {
        return '/api/semester-schedules/'.$schedule->id.$suffix;
    }

    #[Group('smoke')]
    public function test_list_contains_only_owned_schedules_and_empty_list_needs_no_creation(): void
    {
        $user = User::factory()->create();
        $foreign = $this->schedule();
        $this->actingAs($user, 'web')->getJson('/api/semester-schedules')->assertExactJson(['data' => []]);
        $first = $this->schedule($user);
        $second = $this->schedule($user);
        $this->getJson('/api/semester-schedules?user_id='.$foreign->user_id)
            ->assertOk()->assertJsonPath('data.*.id', [$first->id, $second->id]);
    }

    public function test_detail_contains_owned_academic_context_and_explicit_anchor(): void
    {
        $schedule = $this->schedule();
        $period = $schedule->academicPeriod;
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule))->assertExactJson(['data' => [
            'id' => $schedule->id, 'group_name' => 'ПІ-231',
            'academic_period' => ['id' => $period->id, 'name' => $period->name, 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-20'],
            'education_institution' => $period->educationInstitution->only(['id', 'name']),
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator',
        ]]);
    }

    #[DataProvider('readPaths')]
    public function test_all_reads_require_authentication(string $suffix): void
    {
        $schedule = $this->schedule();
        $this->getJson($suffix === 'index' ? '/api/semester-schedules' : $this->url($schedule, $suffix))->assertUnauthorized();
    }

    public static function readPaths(): array
    {
        return [['index'], [''], ['/date/2026-09-08'], ['/week/2026-09-08']];
    }

    #[DataProvider('targetPaths')]
    public function test_foreign_and_missing_targets_return_404_before_date_validation(string $suffix): void
    {
        $foreign = $this->schedule();
        $this->actingAs(User::factory()->create(), 'web')->getJson($this->url($foreign, $suffix))
            ->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->getJson('/api/semester-schedules/99999999'.$suffix)->assertNotFound();
    }

    public static function targetPaths(): array
    {
        return [[''], ['/date/2026-09-08'], ['/week/2026-09-08'], ['/date/invalid'], ['/week/invalid']];
    }

    #[DataProvider('invalidDates')]
    public function test_date_validation_cannot_be_overridden_by_query_arguments(string $suffix): void
    {
        $schedule = $this->schedule();
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule, $suffix).'?date=2026-09-08')
            ->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public static function invalidDates(): array
    {
        return [
            ['/date/invalid'], ['/week/invalid'], ['/date/2026-02-30'], ['/week/2026-02-30'],
            ['/date/2026-9-08'], ['/week/0000-01-01'], ['/week/9999-12-31'],
            ['/date/2026-09-08T00:00:00Z'], ['/date/%202026-09-08%20'], ['/date/2026/09/08'],
        ];
    }

    public function test_day_occurrence_is_a_stable_virtual_resource_with_optional_metadata(): void
    {
        $schedule = $this->schedule();
        $teacher = Teacher::factory()->for($schedule->user)->create();
        $rule = $this->rule($schedule, ['teacher_id' => $teacher->id, 'room' => '221']);
        $url = $this->url($schedule, '/date/2026-09-08');
        $expected = ['data' => [[
            'occurrence_id' => 'semester:'.$schedule->id.':2026-09-08:rule:'.$rule->id,
            'occurs_on' => '2026-09-08', 'source' => 'base', 'pair_number' => 4, 'pair_label' => '7-8',
            'subject' => $rule->subject->only(['id', 'name', 'code', 'color']), 'teacher' => $teacher->only(['id', 'name']),
            'room' => '221', 'starts_at' => '2026-09-08T13:20:00+03:00', 'ends_at' => '2026-09-08T14:40:00+03:00',
            'weekly_lesson_rule_id' => $rule->id, 'schedule_date_exception_id' => null,
        ]], 'meta' => ['timezone' => 'Europe/Kyiv', 'semester_schedule_id' => $schedule->id,
            'start_date' => '2026-09-08', 'end_date' => '2026-09-08']];
        $this->actingAs($schedule->user, 'web')->getJson($url)->assertExactJson($expected);
        $this->getJson($url)->assertExactJson($expected);
        $rule->update(['teacher_id' => null, 'pair_label' => null]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.teacher', null)->assertJsonPath('data.0.pair_label', null)
            ->assertJsonPath('data.0.occurrence_id', $expected['data'][0]['occurrence_id']);
    }

    public function test_iso_week_preserves_pair_numbers_and_alternates_tuesday(): void
    {
        $schedule = $this->schedule();
        $monday = $this->rule($schedule, ['weekday' => 1, 'pair_number' => 1]);
        $numerator = $this->rule($schedule, ['week_pattern' => 'numerator']);
        $denominator = $this->rule($schedule, ['week_pattern' => 'denominator']);
        $this->actingAs($schedule->user, 'web');
        foreach (['2026-09-13' => ['2026-09-07', '2026-09-13', $numerator],
            '2026-09-14' => ['2026-09-14', '2026-09-20', $denominator]] as $anchor => [$start, $end, $tuesday]) {
            $this->getJson($this->url($schedule, '/week/'.$anchor))->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonPath('data.*.weekly_lesson_rule_id', [$monday->id, $tuesday->id])
                ->assertJsonPath('data.*.pair_number', [1, 4])->assertJsonPath('meta.start_date', $start)->assertJsonPath('meta.end_date', $end);
        }
    }

    public function test_replacement_is_frozen_for_one_day_and_deletion_restores_base(): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule, ['weekday' => 1, 'room' => 'base']);
        $exception = $this->snapshot($schedule, $rule, '2026-09-07', changes: ['room' => 'replacement']);
        $rule->update(['room' => 'changed base']);
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule, '/week/2026-09-07'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'replacement')
            ->assertJsonPath('data.0.weekly_lesson_rule_id', null)->assertJsonPath('data.0.schedule_date_exception_id', $exception->id)
            ->assertJsonPath('data.0.room', 'replacement');
        $this->getJson($this->url($schedule, '/date/2026-09-14'))->assertOk()->assertJsonPath('data.0.room', 'changed base');
        $exception->lessons()->delete();
        $this->getJson($this->url($schedule, '/date/2026-09-07'))->assertOk()->assertJsonCount(0, 'data');
        $exception->delete();
        $this->getJson($this->url($schedule, '/date/2026-09-07'))->assertOk()->assertJsonPath('data.0.room', 'changed base')
            ->assertJsonPath('data.0.source', 'base')->assertJsonPath('data.0.weekly_lesson_rule_id', $rule->id);
    }

    public function test_working_saturday_is_independent_from_friday_and_other_saturdays(): void
    {
        $schedule = $this->schedule();
        $friday = $this->rule($schedule, ['weekday' => 5]);
        $this->snapshot($schedule, $friday, '2026-09-12', 'working_saturday', ['room' => 'Saturday']);
        $friday->update(['room' => 'Friday']);
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule, '/week/2026-09-12'))
            ->assertOk()->assertJsonPath('data.*.source', ['base', 'working_saturday'])
            ->assertJsonPath('data.*.room', ['Friday', 'Saturday'])->assertJsonPath('data.*.occurs_on', ['2026-09-11', '2026-09-12']);
        $this->getJson($this->url($schedule, '/date/2026-09-19'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_partial_semester_week_keeps_requested_metadata_and_empty_dates(): void
    {
        $schedule = $this->schedule();
        $schedule->academicPeriod->update(['starts_on' => '2026-09-08', 'ends_on' => '2026-09-08']);
        $this->rule($schedule, ['weekday' => 1]);
        $tuesday = $this->rule($schedule);
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule, '/week/2026-09-13'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.weekly_lesson_rule_id', $tuesday->id)
            ->assertJsonPath('meta.start_date', '2026-09-07')->assertJsonPath('meta.end_date', '2026-09-13');
        foreach (['2026-09-07', '2026-09-09', '2026-09-12'] as $date) {
            $this->getJson($this->url($schedule, '/date/'.$date))->assertExactJson(['data' => [], 'meta' => [
                'timezone' => 'Europe/Kyiv', 'semester_schedule_id' => $schedule->id, 'start_date' => $date, 'end_date' => $date,
            ]]);
        }
    }

    public function test_timezone_and_route_date_come_only_from_trusted_persisted_sources(): void
    {
        $schedule = $this->schedule();
        $this->rule($schedule);
        $owner = $schedule->user;
        $owner->timezone = 'UTC';
        $this->actingAs($owner, 'web')->getJson($this->url($schedule, '/date/2026-09-08').'?date=2026-09-15&timezone=UTC&user_id=99999')
            ->assertOk()->assertJsonPath('meta.timezone', 'Europe/Kyiv')->assertJsonPath('data.0.starts_at', '2026-09-08T13:20:00+03:00');
        $owner->save();
        $this->getJson($this->url($schedule, '/date/2026-09-08'))->assertOk()->assertJsonPath('meta.timezone', 'UTC')
            ->assertJsonPath('data.0.starts_at', '2026-09-08T13:20:00+00:00');
    }

    #[DataProvider('dstDates')]
    public function test_dst_offsets_and_controlled_errors_apply_to_the_complete_week(string $date, string $from, string $to, ?int $elapsed): void
    {
        $schedule = $this->schedule();
        $schedule->academicPeriod->update(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $rule = $this->rule($schedule);
        $this->snapshot($schedule, $rule, $date, changes: ['starts_at_local' => $from, 'ends_at_local' => $to]);
        config(['app.debug' => true]);
        $this->actingAs($schedule->user, 'web');
        foreach (['date', 'week'] as $view) {
            $response = $this->getJson($this->url($schedule, '/'.$view.'/'.$date));
            if ($elapsed === null) {
                $response->assertStatus(409)->assertExactJson(['message' => 'The saved semester schedule configuration is inconsistent.']);
            } else {
                $response->assertOk();
                $row = collect($response->json('data'))->firstWhere('occurs_on', $date);
                $this->assertSame($elapsed, (new \DateTimeImmutable($row['ends_at']))->getTimestamp() - (new \DateTimeImmutable($row['starts_at']))->getTimestamp());
            }
        }
    }

    public static function dstDates(): array
    {
        return [['2026-03-29', '03:30:00', '05:00:00', null], ['2026-10-25', '02:30:00', '03:30:00', null],
            ['2026-03-29', '02:30:00', '04:30:00', 3600], ['2026-10-25', '02:30:00', '04:30:00', 10800]];
    }

    public function test_gets_are_select_only_and_do_not_materialize_or_repair_data(): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule);
        $this->snapshot($schedule, $rule, '2026-09-12', 'working_saturday');
        $tables = ['users', 'academic_periods', 'subjects', 'teachers', 'semester_schedules', 'weekly_lesson_rules',
            'schedule_date_exceptions', 'schedule_date_exception_lessons', 'lessons'];
        $state = fn () => collect($tables)->mapWithKeys(fn ($name) => [$name => DB::table($name)->orderBy('id')->get()->toJson()])->all();
        $before = $state();
        $this->actingAs($schedule->user, 'web');
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (['/api/semester-schedules', $this->url($schedule), $this->url($schedule, '/date/2026-09-08'), $this->url($schedule, '/week/2026-09-12')] as $url) {
            $this->getJson($url)->assertOk();
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/\Aselect\b/i', $query['query']);
        }
        $this->assertSame($before, $state());
    }

    public function test_week_query_count_is_bounded_as_rules_snapshots_and_catalogs_grow(): void
    {
        $schedule = $this->schedule();
        $teacher = Teacher::factory()->for($schedule->user)->create();
        $rule = $this->rule($schedule, ['weekday' => 1, 'pair_number' => 1, 'starts_at_local' => '08:00:00', 'ends_at_local' => '09:00:00', 'teacher_id' => $teacher->id]);
        $this->snapshot($schedule, $rule, '2026-09-07');
        $this->actingAs($schedule->user, 'web');
        $read = function () use ($schedule): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->getJson($this->url($schedule, '/week/2026-09-13'))->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $rows = $response->json('data');
            $this->assertCount(count($rows), array_unique(array_column($rows, 'occurrence_id')));

            return $queries;
        };
        $baseline = $read();
        for ($weekday = 1; $weekday <= 5; $weekday++) {
            for ($pair = 1; $pair <= 4; $pair++) {
                if ($weekday === 1 && $pair === 1) {
                    continue;
                }
                $this->rule($schedule, ['weekday' => $weekday, 'pair_number' => $pair,
                    'starts_at_local' => sprintf('%02d:00:00', 7 + $pair), 'ends_at_local' => sprintf('%02d:00:00', 8 + $pair),
                    'teacher_id' => Teacher::factory()->for($schedule->user)->create()->id]);
            }
        }
        for ($day = 8; $day <= 13; $day++) {
            $this->snapshot($schedule, $rule, '2026-09-'.sprintf('%02d', $day));
        }
        Model::preventLazyLoading();
        try {
            $dense = $read();
        } finally {
            Model::preventLazyLoading(false);
        }
        $this->assertCount(count($baseline), $dense);
        $this->assertLessThanOrEqual(15, count($dense));
        $this->assertCount(1, array_filter($dense, fn ($q) => str_contains($q['query'], '`weekly_lesson_rules`.`semester_schedule_id` =')));
    }

    public function test_legacy_lesson_and_new_semester_reads_remain_separate(): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule);
        $legacy = Lesson::factory()->for($schedule->user)->create(['starts_at' => '2026-09-08 10:20:00', 'ends_at' => '2026-09-08 11:40:00']);
        $this->actingAs($schedule->user, 'web')->getJson('/api/schedule/date/2026-09-08')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $legacy->id)->assertJsonMissingPath('data.0.occurrence_id');
        $this->getJson($this->url($schedule, '/date/2026-09-08'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.weekly_lesson_rule_id', $rule->id)->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.type');
    }

    public function test_inconsistent_period_institution_is_controlled_without_foreign_names(): void
    {
        $schedule = $this->schedule();
        $foreign = EducationInstitution::factory()->create(['name' => 'PRIVATE FOREIGN INSTITUTION']);
        $schedule->academicPeriod->update(['education_institution_id' => $foreign->id]);
        config(['app.debug' => true]);
        $this->actingAs($schedule->user, 'web');
        foreach (['/api/semester-schedules', $this->url($schedule), $this->url($schedule, '/date/2026-09-08'), $this->url($schedule, '/week/2026-09-08')] as $url) {
            $this->getJson($url)->assertStatus(409)->assertExactJson(['message' => 'The saved semester schedule configuration is inconsistent.']);
        }
    }

    public function test_conflicting_saved_rules_fail_the_week_without_partial_output(): void
    {
        $schedule = $this->schedule();
        $this->rule($schedule);
        $this->rule($schedule, ['week_pattern' => 'numerator']);
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule, '/week/2026-09-08'))
            ->assertStatus(409)->assertExactJson(['message' => 'The saved semester schedule configuration is inconsistent.']);
    }

    #[DataProvider('catalogFields')]
    public function test_corrupt_foreign_catalog_reference_never_leaks_data_or_gets_repaired(string $field): void
    {
        $schedule = $this->schedule();
        $rule = $this->rule($schedule);
        $foreign = $field === 'subject_id' ? Subject::factory()->create(['name' => 'PRIVATE FOREIGN SUBJECT'])
            : Teacher::factory()->create(['name' => 'PRIVATE FOREIGN TEACHER']);
        // Deliberately simulate corrupted persisted data only in the guarded test schema.
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            DB::table('weekly_lesson_rules')->where('id', $rule->id)->update([$field => $foreign->id]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
        config(['app.debug' => true]);
        $this->actingAs($schedule->user, 'web');
        foreach (['date', 'week'] as $view) {
            $this->getJson($this->url($schedule, '/'.$view.'/2026-09-08'))->assertStatus(409)
                ->assertExactJson(['message' => 'The saved semester schedule configuration is inconsistent.']);
        }
        $this->assertDatabaseHas('weekly_lesson_rules', ['id' => $rule->id, $field => $foreign->id]);
    }

    public static function catalogFields(): array
    {
        return [['subject_id'], ['teacher_id']];
    }

    public function test_unexpected_errors_do_not_expose_internal_details_in_debug_mode(): void
    {
        $schedule = $this->schedule();
        $service = Mockery::mock(EffectiveScheduleService::class);
        $service->shouldReceive('forDate')->once()->andThrow(new RuntimeException('SQL bindings PRIVATE SECRET'));
        $this->app->instance(EffectiveScheduleService::class, $service);
        config(['app.debug' => true]);
        $this->actingAs($schedule->user, 'web')->getJson($this->url($schedule, '/date/2026-09-08'))
            ->assertStatus(500)->assertExactJson(['message' => 'The semester schedule could not be processed.']);
    }
}
