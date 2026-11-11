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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('mysql')]
class SemesterScheduleImportWorkflowTest extends TestCase
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

        return $this->actingAs($user, 'web')->postJson('/api/semester-schedule-imports', array_replace([
            'academic_period_id' => $period->id, 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator',
            'schedule_file' => new UploadedFile($path ?? self::FIXTURE, 'Приклад розкладу.xlsx', null, null, true),
        ], $options));
    }

    private function confirm(TestResponse $preview, array $changes = []): TestResponse
    {
        return $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/commit', array_replace([
            'confirmed' => true, 'preview_token' => $preview->json('data.preview_token'),
        ], $changes));
    }

    private function imported(): array
    {
        [$user, $period] = $this->context();
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'ready');
        $commit = $this->confirm($preview)->assertOk()->assertJsonPath('data.status', 'committed');
        $schedule = SemesterSchedule::findOrFail($commit->json('data.receipt.semester_schedule_id'));

        return [$user, $period, $schedule, $preview, $commit];
    }

    private function workbook(callable $edit, bool $empty = false): string
    {
        $book = $empty ? new Spreadsheet : IOFactory::load(self::FIXTURE);
        $edit($book->getActiveSheet());
        $path = tempnam(sys_get_temp_dir(), 'semester-import-');
        (new Xlsx($book))->setPreCalculateFormulas(false)->save($path);
        $book->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function snapshot(SemesterSchedule $schedule, WeeklyLessonRule $rule, string $date, string $kind = 'replacement')
    {
        $exception = $schedule->dateExceptions()->create(['user_id' => $schedule->user_id, 'occurs_on' => $date,
            'kind' => $kind, 'copied_weekday' => $kind === 'working_saturday' ? 5 : null]);

        return $exception->lessons()->create([...$rule->only(['user_id', 'semester_schedule_id', 'pair_number', 'pair_label',
            'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room']),
            'source_weekly_rule_id' => $rule->id, 'room' => 'Frozen snapshot'])->refresh();
    }

    #[Group('smoke')]
    public function test_upload_confirm_and_stage2_reads_form_a_complete_weekly_import(): void
    {
        [$user, $period] = $this->context();
        $catalogCounts = [$user->subjects()->count(), $user->teachers()->count()];
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.can_commit', true)->assertJsonCount(16, 'data.preview.rows')->assertJsonPath('data.preview.counts.create', 16);
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
        // Foreign matching names are never resolutions.
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            Subject::factory()->create(['name' => $name]);
        }
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.preview.rows.0.subject.id', null)->assertJsonPath('data.preview.rows.0.teacher.id', null)
            ->assertJsonPath('data.preview.rows.0.subject.resolution', 'create')->assertJsonPath('data.preview.rows.0.teacher.resolution', 'create');
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
        $fresh = $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/revalidate')
            ->assertOk()->assertJsonPath('data.status', 'ready');
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
        $this->assertStringContainsString('Multiple', $preview->json('data.preview.rows.0.errors.teacher_names.0.errors.0'));
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
        $fresh = $this->confirm($preview)->assertStatus(409)->assertJsonPath('message', 'The import preview changed. Review it and confirm again.');
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
        return array_map(fn ($s) => [$s], ['subject', 'teacher', 'timezone', 'period', 'metadata', 'rule', 'snapshot', 'new catalog']);
    }

    public function test_repeated_confirmation_and_identical_reimport_do_not_duplicate_or_touch_rules(): void
    {
        [$user, $period, $schedule, $preview, $commit] = $this->imported();
        $before = DB::table('weekly_lesson_rules')->orderBy('id')->get()->toJson();
        $scheduleBefore = $schedule->getRawOriginal();
        $this->confirm($preview)->assertExactJson($commit->json());
        $fresh = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.preview.counts.noop', 16)
            ->assertJsonPath('data.preview.counts.create', 0)->assertJsonPath('data.preview.counts.delete', 0);
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

    public function test_changed_workbook_updates_one_rule_in_place_and_keeps_frozen_snapshot(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $rule = $schedule->weeklyLessonRules()->where('weekday', 1)->where('pair_number', 1)->sole();
        $snapshot = $this->snapshot($schedule, $rule, '2026-09-07');
        $before = $snapshot->getRawOriginal();
        $path = $this->workbook(fn ($sheet) => $sheet->setCellValue('F4', 'NEW ROOM'));
        $preview = $this->upload($user, $period, $path)->assertCreated()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.preview.counts.update', 1)->assertJsonPath('data.preview.counts.noop', 15);
        $this->confirm($preview)->assertOk();
        $this->assertSame('NEW ROOM', $rule->fresh()->room);
        $this->assertSame($before, $snapshot->fresh()->getRawOriginal());
        $this->getJson('/api/semester-schedules/'.$schedule->id.'/date/2026-09-07')->assertOk()->assertJsonPath('data.0.room', 'Frozen snapshot');
        $this->getJson('/api/semester-schedules/'.$schedule->id.'/date/2026-09-14')->assertOk()->assertJsonPath('data.0.room', 'NEW ROOM');
    }

    public function test_removing_imported_rule_detaches_provenance_without_losing_snapshots(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $rule = $schedule->weeklyLessonRules()->where('weekday', 1)->where('pair_number', 1)->sole();
        $replacement = $this->snapshot($schedule, $rule, '2026-09-07');
        $saturday = $this->snapshot($schedule, $rule, '2026-09-12', 'working_saturday');
        $before = [$replacement->getRawOriginal(), $saturday->getRawOriginal()];
        $path = $this->workbook(function ($sheet) {
            foreach (['D3', 'D4', 'F4'] as $cell) {
                $sheet->setCellValue($cell, null);
            }
        });
        $preview = $this->upload($user, $period, $path)->assertCreated()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.preview.counts.delete', 1)->assertJsonCount(2, 'data.preview.impact.detached_snapshot_references');
        $this->confirm($preview)->assertOk();
        $this->assertModelMissing($rule);
        foreach ([$replacement, $saturday] as $i => $snapshot) {
            $now = $snapshot->fresh()->getRawOriginal();
            unset($before[$i]['source_weekly_rule_id'], $before[$i]['updated_at'], $now['source_weekly_rule_id'], $now['updated_at']);
            $this->assertSame($before[$i], $now);
            $this->assertNull($snapshot->fresh()->source_weekly_rule_id);
        }
        $this->getJson('/api/semester-schedules/'.$schedule->id.'/date/2026-09-12')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'working_saturday')->assertJsonPath('data.0.room', 'Frozen snapshot');
        $this->assertDatabaseCount('schedule_date_exceptions', 2);
        $this->assertDatabaseCount('weekly_lesson_rules', 15);
    }

    public function test_changed_reimport_can_add_update_and_remove_in_one_confirmed_transaction(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $first = $schedule->weeklyLessonRules()->where('weekday', 1)->where('pair_number', 1)->sole();
        $removed = $schedule->weeklyLessonRules()->where('weekday', 1)->where('pair_number', 2)->sole();
        $path = $this->workbook(function ($sheet) {
            $sheet->setCellValue('F4', 'UPDATED');
            foreach (['D7', 'D8', 'F8'] as $cell) {
                $sheet->setCellValue($cell, null);
            }
            $sheet->mergeCells('D35:F35');
            $sheet->mergeCells('D36:E36');
            $sheet->setCellValue('D35', 'Програмування в Інтернет');
            $sheet->setCellValue('D36', 'Когут В.С.');
            $sheet->setCellValue('F36', 'ADDED');
        });
        $preview = $this->upload($user, $period, $path)->assertCreated()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.preview.counts.create', 1)->assertJsonPath('data.preview.counts.update', 1)->assertJsonPath('data.preview.counts.delete', 1);
        $this->confirm($preview)->assertOk();
        $this->assertSame('UPDATED', $first->fresh()->room);
        $this->assertModelMissing($removed);
        $this->assertSame('ADDED', $schedule->weeklyLessonRules()->where('weekday', 3)->where('pair_number', 1)->sole()->room);
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }

    public function test_manually_edited_import_missing_from_reimport_is_preserved(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $rule = $schedule->weeklyLessonRules()->where('weekday', 1)->where('pair_number', 1)->sole();
        $rule->update(['room' => 'Manual correction']);
        $before = $rule->fresh()->getRawOriginal();
        $path = $this->workbook(function ($sheet) {
            foreach (['D3', 'D4', 'F4'] as $cell) {
                $sheet->setCellValue($cell, null);
            }
        });
        $preview = $this->upload($user, $period, $path)->assertCreated()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.preview.counts.delete', 0);
        $this->confirm($preview)->assertOk();
        $this->assertSame($before, $rule->fresh()->getRawOriginal());
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }

    public function test_manual_rule_and_manually_edited_import_are_preserved_or_reported_as_conflicts(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $manual = $schedule->weeklyLessonRules()->create(['user_id' => $user->id, 'weekday' => 1, 'pair_number' => 5,
            'week_pattern' => 'all', 'starts_at_local' => '18:00:00', 'ends_at_local' => '19:00:00', 'subject_id' => $user->subjects()->first()->id])->refresh();
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'ready');
        $this->assertContains($manual->id, $preview->json('data.preview.impact.preserved_rule_ids'));
        $this->confirm($preview)->assertOk();
        $this->assertSame($manual->getRawOriginal(), $manual->fresh()->getRawOriginal());
        $edited = $schedule->weeklyLessonRules()->whereNotNull('import_key')->first();
        $edited->update(['room' => 'Manual correction']);
        $blocked = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'invalid');
        $this->confirm($blocked)->assertUnprocessable();
        $this->assertSame('Manual correction', $edited->fresh()->room);
        $manual->update(['starts_at_local' => '08:30:00', 'ends_at_local' => '09:30:00']);
        $conflict = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'invalid');
        $this->assertNotEmpty($conflict->json('data.preview.errors.schedule'));
        $this->confirm($conflict)->assertUnprocessable();
    }

    public function test_failure_after_partial_inserts_rolls_back_schedule_rules_and_receipt(): void
    {
        [$user, $period] = $this->context();
        $preview = $this->upload($user, $period)->assertCreated();
        $count = 0;
        WeeklyLessonRule::creating(function () use (&$count): void {
            if (++$count === 4) {
                throw new RuntimeException('Injected write failure PRIVATE SQL');
            }
        });
        config(['app.debug' => true]);
        $this->confirm($preview)->assertStatus(500)->assertExactJson(['message' => 'The semester schedule import could not be processed.']);
        $this->assertDatabaseCount('semester_schedules', 0);
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
        $import = SemesterScheduleImport::findOrFail($preview->json('data.id'));
        $this->assertSame('ready', $import->status);
        $this->assertNull($import->receipt);
        WeeklyLessonRule::flushEventListeners();
        $this->confirm($preview)->assertOk();
    }

    public function test_a_cancelled_final_receipt_save_rolls_back_every_domain_write(): void
    {
        [$user, $period] = $this->context();
        $preview = $this->upload($user, $period)->assertCreated();
        SemesterScheduleImport::updating(fn ($import) => $import->status === 'committed' ? false : null);
        $this->confirm($preview)->assertStatus(500);
        $this->assertDatabaseCount('semester_schedules', 0);
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
        $import = SemesterScheduleImport::findOrFail($preview->json('data.id'));
        $this->assertSame('ready', $import->status);
        $this->assertNull($import->receipt);
    }

    public function test_failure_after_detachment_and_delete_restores_the_complete_graph(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $rules = $schedule->weeklyLessonRules()->where('weekday', 1)->orderBy('pair_number')->get();
        $snapshot = $this->snapshot($schedule, $rules[0], '2026-09-07');
        $path = $this->workbook(function ($sheet) {
            $sheet->setCellValue('D1', 'Changed group');
            foreach (['D3', 'D4', 'F4', 'D7', 'D8', 'F8'] as $cell) {
                $sheet->setCellValue($cell, null);
            }
        });
        $preview = $this->upload($user, $period, $path)->assertCreated()->assertJsonPath('data.preview.counts.delete', 2);
        $before = [DB::table('weekly_lesson_rules')->orderBy('id')->get()->toJson(), $snapshot->fresh()->getRawOriginal(), $schedule->fresh()->getRawOriginal()];
        $count = 0;
        WeeklyLessonRule::deleting(function () use (&$count): void {
            if (++$count === 2) {
                throw new RuntimeException('Injected deletion failure');
            }
        });
        $this->confirm($preview)->assertStatus(500);
        $this->assertSame($before, [DB::table('weekly_lesson_rules')->orderBy('id')->get()->toJson(), $snapshot->fresh()->getRawOriginal(), $schedule->fresh()->getRawOriginal()]);
    }

    public function test_workbook_storage_bounds_are_errors_before_commit(): void
    {
        [$user, $period] = $this->context();
        $path = $this->workbook(function ($sheet) {
            $sheet->setCellValue('D1', str_repeat('G', 256));
            $sheet->setCellValue('F4', str_repeat('R', 101));
        });
        $preview = $this->upload($user, $period, $path)->assertCreated()->assertJsonPath('data.status', 'invalid');
        $this->assertNotEmpty($preview->json('data.preview.errors.group_name'));
        $this->assertNotEmpty($preview->json('data.preview.rows.0.errors.room'));
        $this->confirm($preview)->assertUnprocessable();
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
    }

    #[DataProvider('invalidOptions')]
    public function test_bad_inputs_fail_validation_without_import_or_schedule_rows(array $options): void
    {
        [$user, $period] = $this->context();
        $this->upload($user, $period, options: $options)->assertUnprocessable();
        $this->assertDatabaseCount('semester_schedule_imports', 0);
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
    }

    public static function invalidOptions(): array
    {
        return [[['academic_period_id' => '01']],
            [['schedule_file' => null]], [['schedule_file' => UploadedFile::fake()->create('wrong.txt', 1, 'text/plain')]]];
    }

    #[DataProvider('ignoredClientParity')]
    public function test_client_parity_fields_cannot_override_server_owned_import_settings(array $options): void
    {
        [$user, $period] = $this->context();
        $this->upload($user, $period, options: $options)->assertCreated()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.parity_anchor_on', '2026-09-07')->assertJsonPath('data.first_week_parity', 'numerator');
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
    }

    public static function ignoredClientParity(): array
    {
        return [[['parity_anchor_on' => '2026-09-08']], [['parity_anchor_on' => '2026-02-30']], [['first_week_parity' => 'all']]];
    }

    public function test_unsupported_valid_xlsx_returns_failed_review_without_domain_writes(): void
    {
        [$user, $period] = $this->context();
        $path = $this->workbook(fn ($sheet) => $sheet->setCellValue('A1', 'Not a schedule'), true);
        $preview = $this->upload($user, $period, $path)->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.can_commit', false);
        $this->confirm($preview)->assertUnprocessable();
        $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/revalidate')->assertUnprocessable();
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
    }

    public function test_real_multipart_fields_and_read_only_preview_status(): void
    {
        [$user, $period] = $this->context();
        $preview = $this->actingAs($user, 'web')->post('/api/semester-schedule-imports', [
            'academic_period_id' => (string) $period->id, 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator',
            'schedule_file' => new UploadedFile(self::FIXTURE, 'schedule.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'ready');
        $import = SemesterScheduleImport::find($preview->json('data.id'));
        $before = $import->getRawOriginal();
        $this->getJson('/api/semester-schedule-imports/'.$import->id)->assertOk();
        $this->assertSame($before, $import->fresh()->getRawOriginal());
        $this->deleteJson('/api/academic-periods/'.$period->id)->assertUnprocessable();
        $this->assertModelExists($period);
    }

    public function test_unlinked_or_foreign_institution_cannot_be_imported(): void
    {
        [$user, $period] = $this->context();
        $period->update(['education_institution_id' => null]);
        $this->upload($user, $period)->assertUnprocessable()->assertJsonValidationErrors('academic_period_id');
        $period->update(['education_institution_id' => EducationInstitution::factory()->create()->id]);
        $this->upload($user, $period)->assertUnprocessable();
        $this->assertDatabaseCount('semester_schedule_imports', 0);
    }

    public function test_all_import_routes_require_authentication(): void
    {
        [$user, $period] = $this->context();
        $preview = $this->upload($user, $period)->assertCreated();
        Auth::forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/semester-schedule-imports', [])->assertUnauthorized();
        $id = $preview->json('data.id');
        $this->getJson('/api/semester-schedule-imports/'.$id)->assertUnauthorized();
        $this->postJson('/api/semester-schedule-imports/'.$id.'/revalidate')->assertUnauthorized();
        $this->postJson('/api/semester-schedule-imports/'.$id.'/commit', [])->assertUnauthorized();
    }

    public function test_snapshot_change_after_preview_demands_review_and_is_never_overwritten(): void
    {
        [$user, $period, $schedule] = $this->imported();
        $rule = $schedule->weeklyLessonRules()->first();
        $snapshot = $this->snapshot($schedule, $rule, '2026-09-12', 'working_saturday');
        $preview = $this->upload($user, $period)->assertCreated();
        $snapshot->update(['room' => 'Later snapshot edit']);
        $fresh = $this->confirm($preview)->assertStatus(409);
        $this->confirm($fresh)->assertOk();
        $this->assertSame('Later snapshot edit', $snapshot->fresh()->room);
    }

    public function test_identical_manual_slot_is_not_adopted_and_is_not_deleted_on_later_import(): void
    {
        [$user, $period] = $this->context();
        $schedule = $user->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'ПІ-231',
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']);
        $rule = $schedule->weeklyLessonRules()->create(['user_id' => $user->id, 'weekday' => 1, 'pair_number' => 1,
            'pair_label' => '1-2', 'week_pattern' => 'all', 'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00',
            'subject_id' => $user->subjects()->where('name', 'Програмування в Інтернет')->sole()->id,
            'teacher_id' => $user->teachers()->where('name', 'Когут В.С.')->sole()->id, 'room' => '231а'])->refresh();
        $preview = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.preview.counts.preserve', 1)
            ->assertJsonPath('data.preview.counts.create', 15);
        $this->confirm($preview)->assertOk();
        $this->assertNull($rule->fresh()->semester_schedule_import_id);
        $path = $this->workbook(function ($sheet) {
            foreach (['D3', 'D4', 'F4'] as $cell) {
                $sheet->setCellValue($cell, null);
            }
        });
        $removed = $this->upload($user, $period, $path)->assertCreated()->assertJsonPath('data.preview.counts.delete', 0);
        $this->confirm($removed)->assertOk();
        $this->assertSame($rule->getRawOriginal(), $rule->fresh()->getRawOriginal());
    }

    public function test_cross_user_targets_and_foreign_periods_cannot_be_used(): void
    {
        [$user, $period] = $this->context();
        $preview = $this->upload($user, $period)->assertCreated();
        $other = User::factory()->create();
        Auth::forgetGuards();
        $this->actingAs($other, 'web');
        foreach (['', '/revalidate', '/commit'] as $suffix) {
            $path = '/api/semester-schedule-imports/'.$preview->json('data.id').$suffix;
            ($suffix === '' ? $this->getJson($path) : $this->postJson($path, []))->assertNotFound();
        }
        $this->upload($other, $period)->assertUnprocessable();
        $this->assertDatabaseCount('semester_schedule_imports', 1);
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
    }

    public function test_commit_requires_explicit_boolean_confirmation_and_exact_preview_token(): void
    {
        [$user, $period] = $this->context();
        $preview = $this->upload($user, $period)->assertCreated();
        foreach ([false, 'true', 1, '1', null] as $confirmed) {
            $this->confirm($preview, ['confirmed' => $confirmed])->assertUnprocessable();
        }
        $this->confirm($preview, ['preview_token' => str_repeat('f', 64)])->assertStatus(409);
        $this->confirm($preview, ['preview_token' => 'bad'])->assertUnprocessable();
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
        $this->confirm($preview)->assertOk();
    }

    public function test_parity_is_configurable_and_old_excel_api_remains_independent(): void
    {
        [$user, $period] = $this->context();
        $preview = $this->upload($user, $period, options: ['first_week_parity' => 'denominator'])->assertCreated();
        $commit = $this->confirm($preview)->assertOk();
        $base = '/api/semester-schedules/'.$commit->json('data.receipt.semester_schedule_id');
        $this->getJson($base.'/date/2026-09-08')->assertOk()->assertJsonPath('data.3.subject.name', 'Філософія');
        $token = $this->getJson($base.'/management')->assertOk()->json('meta.schedule_token');
        $this->patchJson($base, ['first_week_parity' => 'denominator', 'schedule_token' => $token])->assertOk();
        $rows = $this->getJson($base.'/date/2026-09-08')->assertOk()->json('data');
        $this->assertSame('Проект інтерфейсів користувача ПС', collect($rows)->where('pair_number', 4)->sole()['subject']['name']);
        $legacy = $this->postJson('/api/schedule-imports', ['academic_period_id' => $period->id,
            'schedule_file' => new UploadedFile(self::FIXTURE, 'schedule.xlsx', null, null, true)])
            ->assertCreated()->assertJsonPath('data.total_rows', 30)->assertJsonPath('data.status', 'validated');
        $this->postJson('/api/schedule-imports/'.$legacy->json('data.id').'/commit')->assertOk()->assertJsonPath('data.status', 'committed');
        $this->assertDatabaseCount('lessons', 30);
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }
}
