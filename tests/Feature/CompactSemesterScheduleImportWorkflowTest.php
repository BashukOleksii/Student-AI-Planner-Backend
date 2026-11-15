<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\SemesterSchedule;
use App\Models\SemesterScheduleImport;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use App\Services\Imports\ScheduleWorkbookParser;
use App\Services\Schedule\SemesterLessonTeacherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
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

        $lesson = $exception->lessons()->create([...$rule->only(['user_id', 'semester_schedule_id', 'pair_number', 'pair_label', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room']), 'source_weekly_rule_id' => $rule->id, 'room' => 'Frozen snapshot'])->refresh();
        app(SemesterLessonTeacherService::class)->sync($lesson, $lesson->teacher_id);

        return $lesson;
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
        $before = $this->contents();
        $fresh = $this->confirm($preview)->assertStatus(409)->assertJsonPath('message', 'Попередній огляд імпорту змінився. Перегляньте його та підтвердьте ще раз.');
        $this->assertNotSame($preview->json('data.preview_token'), $fresh->json('data.preview_token'));
        $this->assertSame($before, $this->contents());
        $this->assertNull(SemesterScheduleImport::find($preview->json('data.id'))->committed_at);
        $this->confirm($preview)->assertStatus(409);
        if ($fresh->json('data.can_commit')) {
            $this->confirm($fresh)->assertOk();
        }
    }

    public static function staleMutations(): array
    {
        return array_map(fn ($mutation) => [$mutation], ['subject', 'teacher', 'timezone', 'period', 'metadata', 'rule', 'snapshot', 'new catalog']);
    }

    public function test_repeated_confirmation_is_idempotent_but_a_new_import_replaces_rules(): void
    {
        [$user, $period, $schedule, $preview, $commit] = $this->imported();
        $before = DB::table('weekly_lesson_rules')->orderBy('id')->get()->toJson();
        $scheduleBefore = $schedule->getRawOriginal();
        $this->confirm($preview)->assertExactJson($commit->json());
        $fresh = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.preview.counts.noop', 0)->assertJsonPath('data.preview.counts.create', 16)->assertJsonPath('data.preview.counts.delete', 16);
        $this->confirm($fresh)->assertOk()->assertJsonPath('data.receipt.semester_schedule_id', $schedule->id);
        $this->assertNotSame($before, DB::table('weekly_lesson_rules')->orderBy('id')->get()->toJson());
        $this->assertSame($scheduleBefore['management_revision'] + 1, $schedule->fresh()->management_revision);
        $this->assertDatabaseCount('semester_schedules', 1);
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }

    public function test_two_previews_for_one_period_require_reconfirmation_after_the_first_commit(): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period)->assertCreated();
        $second = $this->upload($user, $period)->assertCreated();
        $commit = $this->confirm($first)->assertOk();
        $changed = $this->confirm($second)->assertStatus(409)->assertJsonPath('data.preview.counts.delete', 16);
        $replaced = $this->confirm($changed)->assertOk();
        $this->assertSame([], array_intersect($commit->json('data.receipt.rule_ids'), $replaced->json('data.receipt.rule_ids')));
        $this->assertDatabaseCount('semester_schedules', 1);
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }

    private function workbook(array $cells = [], bool $empty = false): string
    {
        $book = IOFactory::load(self::FIXTURE);
        $sheet = $book->getActiveSheet();
        if ($empty) {
            for ($row = 2; $row <= 81; $row++) {
                foreach (['D', 'E', 'F'] as $column) {
                    $sheet->setCellValue($column.$row, null);
                }
            }
        }
        foreach ($cells as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        $path = tempnam(sys_get_temp_dir(), 'semester-replacement-');
        $this->temporaryFiles[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function contents(): array
    {
        $state = [];
        foreach (['semester_schedules', 'weekly_lesson_rules', 'schedule_date_exceptions', 'schedule_date_exception_lessons',
            'semester_schedule_suppressed_slots', 'subjects', 'teachers', 'lessons'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach (['subject_teacher' => 'subject_id', 'weekly_lesson_rule_teacher' => 'weekly_lesson_rule_id',
            'schedule_date_exception_lesson_teacher' => 'schedule_date_exception_lesson_id'] as $table => $key) {
            $state[$table] = DB::table($table)->orderBy($key)->orderBy('teacher_id')->get()->toJson();
        }

        return $state;
    }

    private function oldContents(SemesterSchedule $schedule): void
    {
        $rule = $schedule->weeklyLessonRules()->firstOrFail();
        $rule->update(['room' => 'Manual edit', 'is_manually_managed' => true]);
        $manual = $schedule->weeklyLessonRules()->create([...$rule->only(['user_id', 'pair_label', 'week_pattern', 'starts_at_local',
            'ends_at_local', 'subject_id', 'teacher_id']), 'weekday' => 3, 'pair_number' => 4,
            'starts_at_local' => '10:00:00', 'ends_at_local' => '11:20:00']);
        app(SemesterLessonTeacherService::class)->sync($manual, $manual->teacher_id);
        $this->snapshot($schedule, $rule, '2026-09-07');
        $this->snapshot($schedule, $rule, '2026-09-12', 'working_saturday');
        $schedule->suppressedSlots()->create(['user_id' => $schedule->user_id, 'weekday' => 5, 'pair_number' => 4, 'week_pattern' => 'all']);
    }

    public function test_confirmation_replaces_all_contents_of_only_selected_period(): void
    {
        [$user, $period, $schedule, $original] = $this->imported();
        $this->oldContents($schedule);
        $otherPeriod = AcademicPeriod::factory()->for($user)->create();
        $other = $user->semesterSchedules()->create(['academic_period_id' => $otherPeriod->id, 'group_name' => 'Other',
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'denominator']);
        $otherRule = $other->weeklyLessonRules()->create($schedule->weeklyLessonRules()->first()->only(['user_id', 'weekday', 'pair_number',
            'pair_label', 'week_pattern', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id']))->refresh();
        $otherSnapshot = $this->snapshot($other, $otherRule, '2026-09-07');
        $lesson = Lesson::factory()->for($user)->create(['academic_period_id' => $period->id])->refresh();
        $other->refresh();
        $before = $this->contents();
        $oldIds = $schedule->weeklyLessonRules()->pluck('id')->all();
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.preview.strategy', 'replace')->assertJsonPath('data.preview.counts.delete', 17)
            ->assertJsonPath('data.preview.impact.deleted_rule_ids', $oldIds)
            ->assertJsonCount(2, 'data.preview.impact.deleted_exceptions')->assertJsonCount(1, 'data.preview.impact.deleted_suppressed_slots');
        $preview = $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/revalidate')->assertOk();
        $this->assertSame($before, $this->contents());
        $receipt = $this->confirm($preview)->assertOk()->assertJsonPath('data.receipt.semester_schedule_id', $schedule->id)
            ->assertJsonPath('data.receipt.removed.rule_ids', $oldIds);
        $this->assertSame(16, $schedule->weeklyLessonRules()->count());
        $this->assertSame(0, $schedule->dateExceptions()->count());
        $this->assertSame(0, $schedule->suppressedSlots()->count());
        $this->assertSame([], array_intersect($oldIds, $receipt->json('data.receipt.rule_ids')));
        $this->assertSame($otherRule->getRawOriginal(), $otherRule->fresh()->getRawOriginal());
        $this->assertSame($otherSnapshot->getRawOriginal(), $otherSnapshot->fresh()->getRawOriginal());
        $this->assertSame($other->getRawOriginal(), $other->fresh()->getRawOriginal());
        $this->assertSame($lesson->getRawOriginal(), $lesson->fresh()->getRawOriginal());
        $this->assertDatabaseCount('semester_schedule_imports', 2);
        $this->getJson('/api/semester-schedule-imports/'.$original->json('data.id'))->assertJsonPath('data.status', 'committed');
        $after = $this->contents();
        $this->confirm($preview)->assertExactJson($receipt->json());
        $this->assertSame($after, $this->contents());
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_or_empty_import_keeps_all_old_contents(string $case): void
    {
        [$user, $period, $schedule] = $this->imported();
        $this->oldContents($schedule);
        $before = $this->contents();
        $path = match ($case) {
            'empty' => $this->workbook(empty: true),
            'two teachers' => $this->workbook(['D4' => 'Студницька Л. М..; Інший А. Б.']),
            'contradictory subject' => $this->workbook(['D7' => 'Програмування в Інтернет']),
            'malformed' => $this->workbook(['C3' => '=1+1']),
        };
        $preview = $this->upload($user, $period, $path)->assertCreated()->assertJsonPath('data.can_commit', false);
        $this->confirm($preview)->assertUnprocessable();
        $this->assertSame($before, $this->contents());
    }

    public static function invalidFiles(): array
    {
        return array_map(fn ($case) => [$case], ['empty', 'two teachers', 'contradictory subject', 'malformed']);
    }

    #[DataProvider('writeFailures')]
    public function test_write_failure_rolls_back_deletions_catalogs_assignments_and_receipt(string $stage): void
    {
        [$user, $period, $schedule] = $this->imported();
        $this->oldContents($schedule);
        $before = $this->contents();
        $preview = $this->upload($user, $period, $this->workbook(['D3' => 'Новий предмет', 'D24' => 'Новий А. Б.']))
            ->assertCreated()->assertJsonPath('data.status', 'ready');
        if ($stage === 'rule') {
            $writes = 0;
            WeeklyLessonRule::creating(function () use (&$writes) {
                return ++$writes !== 2;
            });
        } else {
            SemesterScheduleImport::updating(fn ($import) => $import->status !== 'committed');
        }
        $this->withoutExceptionHandling();
        try {
            $this->confirm($preview);
            $this->fail('A failed write must abort the replacement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The semester import write failed.', $exception->getMessage());
        }
        $this->assertSame($before, $this->contents());
        $batch = SemesterScheduleImport::findOrFail($preview->json('data.id'));
        $this->assertSame('ready', $batch->status);
        $this->assertSame($preview->json('data.preview_token'), $batch->preview_token);
        $this->assertNull($batch->receipt);
        $this->assertNull($batch->committed_at);
    }

    public static function writeFailures(): array
    {
        return [['rule'], ['receipt']];
    }

    #[DataProvider('legacyStatuses')]
    public function test_legacy_merge_preview_requires_reconfirmation(string $status): void
    {
        [$user, $period, $schedule] = $this->imported();
        $this->oldContents($schedule);
        $preview = $this->upload($user, $period)->assertCreated();
        $batch = SemesterScheduleImport::findOrFail($preview->json('data.id'));
        $review = $batch->review;
        unset($review['strategy']);
        $batch->update(['review' => $review, 'status' => $status]);
        $before = $this->contents();
        $fresh = $this->confirm($preview)->assertConflict()->assertJsonPath('data.preview.strategy', 'replace');
        $this->assertSame($before, $this->contents());
        $this->confirm($fresh)->assertOk();
    }

    public static function legacyStatuses(): array
    {
        return [['ready'], ['invalid']];
    }

    #[DataProvider('teacherCatalogStates')]
    public function test_one_teacher_for_two_subjects_and_repeated_initial_dot_reuse_one_identity(bool $existing): void
    {
        [$user, $period] = $this->context(false);
        $teacher = $existing ? Teacher::factory()->for($user)->create(['name' => 'Студницька Л. М.']) : null;
        $preview = $this->upload($user, $period, $this->workbook(['D3' => 'Математичні методи', 'D4' => 'Студницька Л. М..',
            'D7' => 'Дискретна математика', 'D8' => 'Студницька Л. М.']))->assertCreated()->assertJsonPath('data.status', 'ready');
        $this->assertSame($existing ? 1 : 0, $user->teachers()->count());
        $this->assertSame(0, $user->subjects()->count());
        $this->confirm($preview)->assertOk();
        $teacher ??= $user->teachers()->where('name', 'Студницька Л. М.')->sole();
        $subjectIds = $user->subjects()->whereIn('name', ['Математичні методи', 'Дискретна математика'])->pluck('id');
        $this->assertSame([$teacher->id], WeeklyLessonRule::whereIn('subject_id', $subjectIds)->distinct()->pluck('teacher_id')->all());
        $this->assertSame(2, DB::table('subject_teacher')->whereIn('subject_id', $subjectIds)->where('teacher_id', $teacher->id)->count());
        $this->assertSame(1, $user->teachers()->where('name', 'like', 'Студницька%')->count());
    }

    public static function teacherCatalogStates(): array
    {
        return [[true], [false]];
    }

    public function test_teacher_change_replaces_assignment_proven_only_by_removed_schedule(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $subject = $user->subjects()->where('name', 'Фізичне виховання')->firstOrFail();
        $oldTeacher = $subject->teachers()->sole();
        $rule = $schedule->weeklyLessonRules()->where('subject_id', $subject->id)->sole();
        $this->snapshot($schedule, $rule, '2026-09-08');
        $preview = $this->upload($user, $period, $this->workbook(['D24' => 'Новий А. Б.']))->assertCreated()
            ->assertJsonPath('data.status', 'ready')->assertJsonPath('data.preview.impact.teacher_assignment_changes.0.subject_id', $subject->id);
        $this->assertSame([$oldTeacher->id], $subject->teachers()->pluck('teachers.id')->all());
        $this->assertDatabaseMissing('teachers', ['user_id' => $user->id, 'name' => 'Новий А. Б.']);
        $this->confirm($preview)->assertOk();
        $newTeacher = $user->teachers()->where('name', 'Новий А. Б.')->sole();
        $this->assertSame([$newTeacher->id], $subject->teachers()->pluck('teachers.id')->all());
        $this->assertSame($newTeacher->id, $schedule->weeklyLessonRules()->where('subject_id', $subject->id)->sole()->teacher_id);
        $this->assertNotNull($oldTeacher->fresh());
    }

    #[DataProvider('retainedAssignments')]
    public function test_teacher_change_cannot_overwrite_retained_or_unattributed_assignment(string $dependency): void
    {
        [$user, $period, $schedule] = $this->imported();
        $subject = $user->subjects()->where('name', 'Фізичне виховання')->sole();
        $rule = $schedule->weeklyLessonRules()->where('subject_id', $subject->id)->sole();
        if ($dependency === 'catalog') {
            $rule->delete();
        } elseif ($dependency === 'lesson') {
            Lesson::factory()->for($user)->create(['subject_id' => $subject->id, 'teacher_id' => $rule->teacher_id]);
        } else {
            $otherPeriod = AcademicPeriod::factory()->for($user)->create();
            $other = $user->semesterSchedules()->create(['academic_period_id' => $otherPeriod->id, 'group_name' => 'Other',
                'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']);
            $otherRule = $other->weeklyLessonRules()->create($rule->only(['user_id', 'weekday', 'pair_number', 'pair_label',
                'week_pattern', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id']));
            if ($dependency === 'exception') {
                $snapshot = $this->snapshot($other, $otherRule, '2026-09-08');
                $snapshot->update(['source_weekly_rule_id' => null]);
                $otherRule->delete();
            }
        }
        $before = $this->contents();
        $preview = $this->upload($user, $period, $this->workbook(['D24' => 'Новий А. Б.']))->assertCreated()
            ->assertJsonPath('data.status', 'invalid');
        $this->assertStringContainsString('поза розкладом', $preview->json('data.preview.rows.5.errors.teacher_id.0'));
        $this->confirm($preview)->assertUnprocessable();
        $this->assertSame($before, $this->contents());
    }

    public static function retainedAssignments(): array
    {
        return [['catalog'], ['other semester'], ['exception'], ['lesson']];
    }

    public function test_new_external_dependency_invalidates_teacher_change_without_clearing_schedule(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $preview = $this->upload($user, $period, $this->workbook(['D24' => 'Новий А. Б.']))->assertCreated()->assertJsonPath('data.status', 'ready');
        $subject = $user->subjects()->where('name', 'Фізичне виховання')->sole();
        $rule = $schedule->weeklyLessonRules()->where('subject_id', $subject->id)->sole();
        $otherPeriod = AcademicPeriod::factory()->for($user)->create();
        $other = $user->semesterSchedules()->create(['academic_period_id' => $otherPeriod->id, 'group_name' => 'Other',
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']);
        $other->weeklyLessonRules()->create($rule->only(['user_id', 'weekday', 'pair_number', 'pair_label',
            'week_pattern', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id']));
        $before = $this->contents();
        $this->confirm($preview)->assertConflict()->assertJsonPath('data.status', 'invalid');
        $this->assertSame($before, $this->contents());
    }

    public function test_old_import_baseline_does_not_block_replacement(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $rule = $schedule->weeklyLessonRules()->firstOrFail();
        $rule->update(['import_baseline' => ['legacy' => true]]);
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'ready');
        $this->assertSame(['legacy' => true], $rule->fresh()->import_baseline);
        $this->confirm($preview)->assertOk();
        $this->assertNull($rule->fresh());
        $this->assertSame(16, $schedule->weeklyLessonRules()->count());
    }
}
