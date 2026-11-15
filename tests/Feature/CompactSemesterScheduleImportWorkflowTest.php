<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\SemesterSchedule;
use App\Models\SemesterScheduleImport;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use App\Services\Imports\ScheduleWorkbookParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactSemesterScheduleImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx';

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        WeeklyLessonRule::flushEventListeners();
        SemesterScheduleImport::flushEventListeners();
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function context(bool $catalogs = true): array
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($user)->create();
        $period = AcademicPeriod::factory()->for($user)->for($institution)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);
        $template = app(ScheduleWorkbookParser::class)->parse(self::FIXTURE);
        if ($catalogs) {
            foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
                Subject::factory()->for($user)->create(['name' => $name]);
            }
            foreach (array_unique(array_column($template['rules'], 'teacher_name')) as $name) {
                Teacher::factory()->for($user)->create(['name' => $name]);
            }
        }

        return [$user, $period, $template];
    }

    private function upload(User $user, AcademicPeriod $period, ?string $path = null, array $options = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->actingAs($user, 'web')->postJson('/api/semester-schedule-imports', array_replace(['academic_period_id' => $period->id, 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator', 'schedule_file' => new UploadedFile($path ?? self::FIXTURE, 'Приклад розкладу.xlsx', null, null, true)], $options));
    }

    private function confirm(TestResponse $preview, array $changes = []): TestResponse
    {
        return $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/commit', array_replace(['confirmed' => true, 'preview_token' => $preview->json('data.preview_token')], $changes));
    }

    private function imported(): array
    {
        [$user, $period] = $this->context();
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'ready');
        $commit = $this->confirm($preview)->assertOk()->assertJsonPath('data.status', 'committed');
        $schedule = SemesterSchedule::findOrFail($commit->json('data.receipt.semester_schedule_id'));

        return [$user, $period, $schedule, $preview, $commit];
    }

    private function snapshot(SemesterSchedule $schedule, WeeklyLessonRule $rule, string $date, string $kind = 'replacement')
    {
        $exception = $schedule->dateExceptions()->create(['user_id' => $schedule->user_id, 'occurs_on' => $date, 'kind' => $kind, 'copied_weekday' => $kind === 'working_saturday' ? 5 : null]);

        return $exception->lessons()->create([...$rule->only(['user_id', 'semester_schedule_id', 'pair_number', 'pair_label', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room']), 'source_weekly_rule_id' => $rule->id, 'room' => 'Frozen snapshot'])->refresh();
    }

    public function test_upload_confirm_and_stage2_reads_form_a_complete_weekly_import(): void
    {
        [$user, $period] = $this->context();
        $catalogCounts = [$user->subjects()->count(), $user->teachers()->count()];
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.can_commit', true)->assertJsonCount(16, 'data.preview.rows')->assertJsonPath('data.preview.counts.create', 16);
        $this->assertDatabaseCount('semester_schedules', 0);
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('schedule_import_batches', 0);
        $this->getJson('/api/semester-schedule-imports/'.$preview->json('data.id'))->assertExactJson($preview->json());
        $commit = $this->confirm($preview)->assertOk()->assertJsonPath('data.status', 'committed')->assertJsonCount(16, 'data.receipt.rule_ids');
        $id = $commit->json('data.receipt.semester_schedule_id');
        foreach (['2026-09-08' => 'Філософія', '2026-09-15' => 'Проект інтерфейсів користувача ПС'] as $date => $name) {
            $rows = $this->getJson('/api/semester-schedules/'.$id.'/date/'.$date)->assertOk()->assertJsonCount(4, 'data')->json('data');
            $last = collect($rows)->where('pair_number', 4)->sole();
            $this->assertSame($name, $last['subject']['name']);
            $this->assertSame('7-8', $last['pair_label']);
            $this->assertSame($date.'T13:20:00+03:00', $last['starts_at']);
        }
        foreach (['2026-09-07', '2026-09-14'] as $date) {
            $rows = $this->getJson('/api/semester-schedules/'.$id.'/week/'.$date)->assertOk()->assertJsonCount(15, 'data')->json('data');
            $this->assertSame([1, 2, 3, 4], array_values(array_unique(array_column($rows, 'pair_number'))));
        }
        $this->assertSame($catalogCounts, [$user->subjects()->count(), $user->teachers()->count()]);
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
        $this->assertDatabaseCount('lessons', 0);
        $this->assertNull(WeeklyLessonRule::first()->source_import_batch_id);
        $this->assertArrayNotHasKey('template', $preview->json('data'));
        $this->assertArrayNotHasKey('context_hash', $preview->json('data'));
        $this->assertArrayNotHasKey('user_id', $preview->json('data'));
    }

    public function test_missing_catalogs_are_prepared_without_writes_and_manual_catalog_changes_require_revalidation(): void
    {
        [$user, $period, $template] = $this->context(false);
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            Subject::factory()->create(['name' => $name]);
        }
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.preview.rows.0.subject.id', null)->assertJsonPath('data.preview.rows.0.teacher.id', null)->assertJsonPath('data.preview.rows.0.subject.resolution', 'create')->assertJsonPath('data.preview.rows.0.teacher.resolution', 'create');
        $this->assertSame(0, $user->subjects()->count());
        $this->assertSame(0, $user->teachers()->count());
        $this->confirm($preview, ['confirmed' => false])->assertUnprocessable();
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            $this->postJson('/api/subjects', ['name' => $name])->assertCreated();
        }
        foreach (array_unique(array_column($template['rules'], 'teacher_name')) as $name) {
            $this->postJson('/api/teachers', ['name' => $name])->assertCreated();
        }
        $fresh = $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/revalidate')->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertNotSame($preview->json('data.preview_token'), $fresh->json('data.preview_token'));
        $this->confirm($preview)->assertStatus(409);
        $this->confirm($fresh)->assertOk()->assertJsonPath('data.status', 'committed');
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }

    public function test_ambiguous_owned_teacher_requires_catalog_repair(): void
    {
        [$user, $period, $template] = $this->context();
        $duplicate = Teacher::factory()->for($user)->create(['name' => $template['rules'][0]['teacher_name']]);
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'invalid');
        $this->assertStringContainsString('Кілька ваших викладачів', $preview->json('data.preview.rows.0.errors.teacher_names.0.errors.0'));
        $this->patchJson('/api/teachers/'.$duplicate->id, ['name' => 'Different owned teacher'])->assertOk();
        $fresh = $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/revalidate')->assertOk()->assertJsonPath('data.status', 'ready');
        $this->confirm($fresh)->assertOk();
    }

    #[DataProvider('staleMutations')]
    public function test_changed_authoritative_state_invalidates_confirmation(string $mutation): void
    {
        [$user, $period, $schedule] = $this->imported();
        $preview = $this->upload($user, $period)->assertCreated();
        $rule = $schedule->weeklyLessonRules()->first();
        match ($mutation) {
            'subject' => $rule->subject->update(['code' => 'CHANGED']),
            'teacher' => $rule->teacher->update(['name' => 'Renamed teacher']),
            'timezone' => $user->update(['timezone' => 'UTC']),
            'period' => $period->update(['ends_on' => '2026-09-21']),
            'metadata' => $schedule->update(['group_name' => 'Manual group']),
            'rule' => $rule->update(['room' => 'Manual room']),
            'snapshot' => $this->snapshot($schedule, $rule, '2026-09-07'),
            'new catalog' => Teacher::factory()->for($user)->create(),
        };
        $before = DB::table('weekly_lesson_rules')->orderBy('id')->get()->toJson();
        $fresh = $this->confirm($preview)->assertStatus(409)->assertJsonPath('message', 'Попередній огляд імпорту змінився. Перегляньте його та підтвердьте ще раз.');
        $this->assertNotSame($preview->json('data.preview_token'), $fresh->json('data.preview_token'));
        $this->assertSame($before, DB::table('weekly_lesson_rules')->orderBy('id')->get()->toJson());
        $this->assertNull(SemesterScheduleImport::find($preview->json('data.id'))->committed_at);
        $this->confirm($preview)->assertStatus(409);
        if ($fresh->json('data.can_commit')) {
            $this->confirm($fresh)->assertOk();
        }
    }

    public static function staleMutations(): array
    {
        return [0 => ['subject']];
    }

    public function test_repeated_confirmation_and_identical_reimport_do_not_duplicate_or_touch_rules(): void
    {
        [$user, $period, $schedule, $preview, $commit] = $this->imported();
        $before = DB::table('weekly_lesson_rules')->orderBy('id')->get()->toJson();
        $scheduleBefore = $schedule->getRawOriginal();
        $this->confirm($preview)->assertExactJson($commit->json());
        $fresh = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.preview.counts.noop', 16)->assertJsonPath('data.preview.counts.create', 0)->assertJsonPath('data.preview.counts.delete', 0);
        $this->confirm($fresh)->assertOk()->assertJsonPath('data.receipt.semester_schedule_id', $schedule->id);
        $this->assertSame($before, DB::table('weekly_lesson_rules')->orderBy('id')->get()->toJson());
        $this->assertSame($scheduleBefore, $schedule->fresh()->getRawOriginal());
        $this->assertDatabaseCount('semester_schedules', 1);
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }

    public function test_two_previews_for_one_period_require_reconfirmation_after_the_first_commit(): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period)->assertCreated();
        $second = $this->upload($user, $period)->assertCreated();
        $commit = $this->confirm($first)->assertOk();
        $changed = $this->confirm($second)->assertStatus(409)->assertJsonPath('data.preview.counts.noop', 16);
        $this->confirm($changed)->assertOk()->assertJsonPath('data.receipt.rule_ids', $commit->json('data.receipt.rule_ids'));
        $this->assertDatabaseCount('semester_schedules', 1);
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }
}
