<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\SemesterSchedule;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use App\Services\Imports\ScheduleWorkbookParser;
use App\Services\Schedule\SemesterScheduleStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql')]
class SemesterScheduleManagementImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private AcademicPeriod $period;

    private int $scheduleId;

    private TestResponse $original;

    private TestResponse $receipt;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($this->owner)->create();
        $this->period = AcademicPeriod::factory()->for($this->owner)->for($institution)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);
        $template = app(ScheduleWorkbookParser::class)->parse($this->fixture());
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            Subject::factory()->for($this->owner)->create(['name' => $name]);
        }
        foreach (array_unique(array_column($template['rules'], 'teacher_name')) as $name) {
            Teacher::factory()->for($this->owner)->create(['name' => $name]);
        }
        $this->actingAs($this->owner, 'web');
        $this->original = $this->preview()->assertCreated()->assertJsonPath('data.status', 'ready');
        $this->receipt = $this->confirm($this->original)->assertOk();
        $this->scheduleId = $this->receipt->json('data.receipt.semester_schedule_id');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    private function fixture(): string
    {
        return base_path('tests/Fixtures/ScheduleImports/Приклад розкладу.xlsx');
    }

    private function url(string $suffix = ''): string
    {
        return '/api/semester-schedules/'.$this->scheduleId.$suffix;
    }

    private function preview(?string $path = null): TestResponse
    {
        return $this->postJson('/api/semester-schedule-imports', ['academic_period_id' => $this->period->id,
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator',
            'schedule_file' => new UploadedFile($path ?? $this->fixture(), 'schedule.xlsx', null, null, true)]);
    }

    private function confirm(TestResponse $preview): TestResponse
    {
        return $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/commit',
            ['confirmed' => true, 'preview_token' => $preview->json('data.preview_token')]);
    }

    private function mutate(string $method, string $path, array $values): TestResponse
    {
        $token = $this->getJson($this->url('/management'))->assertOk()->json('meta.schedule_token');

        return $this->json($method, $this->url($path), ['schedule_token' => $token, ...$values]);
    }

    private function firstRule(): WeeklyLessonRule
    {
        return WeeklyLessonRule::where('semester_schedule_id', $this->scheduleId)->orderBy('id')->firstOrFail();
    }

    public function test_manual_imported_edit_keeps_baseline_and_is_permanently_protected_even_after_restoring_values(): void
    {
        $rule = $this->firstRule();
        $baseline = $rule->import_baseline;
        $this->mutate('PATCH', '/weekly-rules/'.$rule->id, ['room' => 'MANUAL'])->assertOk();
        $changed = $rule->fresh();
        $this->assertSame($baseline, $changed->import_baseline);
        $this->assertSame($rule->semester_schedule_import_id, $changed->semester_schedule_import_id);
        $this->assertTrue($changed->is_manually_managed);
        $blocked = $this->preview()->assertJsonPath('data.status', 'invalid');
        $this->assertNotEmpty($blocked->json('data.preview.rows.0.errors.protected_rule'));
        $this->confirm($blocked)->assertUnprocessable();
        $this->mutate('PATCH', '/weekly-rules/'.$rule->id, ['room' => $rule->room])->assertOk();
        $same = $this->preview()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.preview.rows.0.operation', 'preserve');
        $this->confirm($same)->assertOk();
        $this->assertTrue($rule->fresh()->is_manually_managed);
        $this->assertSame($baseline, $rule->fresh()->import_baseline);
    }

    public function test_manual_deletion_blocks_same_workbook_until_explicit_release_and_new_confirmation(): void
    {
        $rule = $this->firstRule();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base'])->assertOk();
        $snapshot = $this->getJson($this->url('/date-exceptions/2026-09-07'))->json('data');
        $waiting = $this->preview()->assertJsonPath('data.status', 'ready');
        $this->mutate('DELETE', '/weekly-rules/'.$rule->id, ['confirmed' => true])->assertOk();
        $stale = $this->confirm($waiting)->assertConflict()->assertJsonPath('data.status', 'invalid');
        $stale->assertJsonPath('data.preview.rows.0.operation', 'suppressed');
        $this->assertNotEmpty($stale->json('data.preview.rows.0.errors.suppressed_slot'));
        $this->assertDatabaseCount('weekly_lesson_rules', 15);
        $this->confirm($this->original)->assertOk()->assertJsonPath('data.receipt', fn ($actual) => app(SemesterScheduleStateService::class)->hash($actual) === app(SemesterScheduleStateService::class)->hash($this->receipt->json('data.receipt')));
        $this->assertDatabaseCount('weekly_lesson_rules', 15);
        $new = $this->preview()->assertJsonPath('data.status', 'invalid');
        $slotId = $new->json('data.preview.rows.0.suppressed_slot_ids.0');
        $this->assertIsInt($slotId);
        $this->confirm($new)->assertUnprocessable();
        $this->mutate('DELETE', '/suppressed-slots/'.$slotId, ['confirmed' => true])->assertOk();
        $review = $this->postJson('/api/semester-schedule-imports/'.$new->json('data.id').'/revalidate')->assertOk()->assertJsonPath('data.status', 'ready');
        $this->confirm($review)->assertOk();
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
        $this->getJson($this->url('/date-exceptions/2026-09-07'))->assertJsonPath('data.id', $snapshot['id'])
            ->assertJsonPath('data.lessons.*.id', array_column($snapshot['lessons'], 'id'))->assertJsonPath('data.lessons.0.source_weekly_rule_id', null);
        $this->getJson($this->url('/week/2026-09-14'))->assertJsonCount(15, 'data');
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_imported_identity_move_and_later_delete_protect_both_original_and_current_slot(): void
    {
        $rule = $this->firstRule();
        $this->mutate('PATCH', '/weekly-rules/'.$rule->id, ['weekday' => 5, 'pair_number' => 255,
            'starts_at_local' => '20:00:00', 'ends_at_local' => '21:00:00'])->assertOk();
        $this->assertSame($rule->import_key, $rule->fresh()->import_key);
        $this->assertSame($rule->import_baseline, $rule->fresh()->import_baseline);
        $this->assertDatabaseHas('semester_schedule_suppressed_slots', ['semester_schedule_id' => $this->scheduleId,
            'weekday' => $rule->weekday, 'pair_number' => $rule->pair_number, 'week_pattern' => $rule->week_pattern]);
        $this->preview()->assertJsonPath('data.status', 'invalid')->assertJsonPath('data.preview.rows.0.operation', 'suppressed');
        $this->mutate('DELETE', '/weekly-rules/'.$rule->id, ['confirmed' => true])->assertOk();
        $this->assertDatabaseCount('semester_schedule_suppressed_slots', 2);
        $this->assertDatabaseHas('semester_schedule_suppressed_slots', ['weekday' => 5, 'pair_number' => 255]);
        $this->preview()->assertJsonPath('data.status', 'invalid');
        $this->assertDatabaseCount('weekly_lesson_rules', 15);
    }

    public function test_changed_reimport_preserves_compatible_manual_rule_and_all_frozen_snapshots(): void
    {
        $rule = $this->firstRule();
        $this->mutate('PATCH', '/weekly-rules/'.$rule->id, ['room' => 'MANUAL'])->assertOk();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base'])->assertOk();
        $this->mutate('POST', '/working-saturdays', ['occurs_on' => '2026-09-12', 'copied_weekday' => 1])->assertCreated();
        $before = $this->getJson($this->url('/date-exceptions'))->json('data');
        $book = IOFactory::load($this->fixture());
        $book->getActiveSheet()->setCellValue('F4', 'MANUAL');
        $book->getActiveSheet()->setCellValue('F8', 'IMPORTED UPDATE');
        $path = tempnam(sys_get_temp_dir(), 'semester-stage4-');
        $this->temporaryFiles[] = $path;
        (new Xlsx($book))->setPreCalculateFormulas(false)->save($path);
        $book->disconnectWorksheets();
        $preview = $this->preview($path)->assertJsonPath('data.status', 'ready')->assertJsonPath('data.preview.rows.0.operation', 'preserve')
            ->assertJsonPath('data.preview.counts.update', 1);
        $this->confirm($preview)->assertOk();
        $this->assertSame($before, $this->getJson($this->url('/date-exceptions'))->json('data'));
        $this->assertSame('MANUAL', $rule->fresh()->room);
        $this->assertTrue($rule->fresh()->is_manually_managed);
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }

    #[DataProvider('mutations')]
    public function test_affected_management_changes_stale_existing_import_preview(string $change): void
    {
        $rule = $this->firstRule();
        if (in_array($change, ['delete_exception', 'release_suppression'], true)) {
            $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base'])->assertOk();
        }
        if ($change === 'release_suppression') {
            // A protected slot not present in the workbook still participates in
            // the graph fingerprint, without invalidating the ready preview.
            $slot = SemesterSchedule::find($this->scheduleId)->suppressedSlots()->create(['user_id' => $this->owner->id,
                'weekday' => 5, 'pair_number' => 255, 'week_pattern' => 'all']);
        }
        $preview = $this->preview()->assertJsonPath('data.status', 'ready');
        match ($change) {
            'metadata' => $this->mutate('PATCH', '', ['first_week_parity' => 'denominator'])->assertOk(),
            'rule' => $this->mutate('PATCH', '/weekly-rules/'.$rule->id, ['room' => 'changed'])->assertOk(),
            'cancellation' => $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'replace', 'lessons' => []])->assertOk(),
            'saturday' => $this->mutate('POST', '/working-saturdays', ['occurs_on' => '2026-09-12', 'copied_weekday' => 1])->assertCreated(),
            'delete_exception' => $this->mutate('DELETE', '/date-exceptions/2026-09-07', ['confirmed' => true])->assertOk(),
            'release_suppression' => $this->mutate('DELETE', '/suppressed-slots/'.$slot->id, ['confirmed' => true])->assertOk(),
        };
        $before = $this->getJson($this->url('/management'))->json();
        $this->confirm($preview)->assertConflict();
        $this->assertSame($before, $this->getJson($this->url('/management'))->json());
        $this->confirm($this->original)->assertOk()->assertJsonPath('data.receipt', fn ($actual) => app(SemesterScheduleStateService::class)->hash($actual) === app(SemesterScheduleStateService::class)->hash($this->receipt->json('data.receipt')));
    }

    public static function mutations(): array
    {
        return [['metadata'], ['rule'], ['cancellation'], ['saturday'], ['delete_exception'], ['release_suppression']];
    }

    public function test_metadata_edit_then_revert_still_stales_an_import_preview(): void
    {
        $preview = $this->preview()->assertJsonPath('data.status', 'ready');
        $this->travelTo(now()->startOfSecond());
        $this->mutate('PATCH', '', ['group_name' => 'temporary'])->assertOk();
        $this->mutate('PATCH', '', ['group_name' => 'ПІ-231'])->assertOk();
        $this->confirm($preview)->assertConflict()->assertJsonPath('data.status', 'ready');
        $this->travelBack();
    }

    public function test_import_history_prevents_schedule_deletion_even_if_its_rules_are_gone(): void
    {
        WeeklyLessonRule::where('semester_schedule_id', $this->scheduleId)->delete();
        $this->mutate('DELETE', '', ['confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('semester_schedule');
        $this->confirm($this->original)->assertOk()->assertJsonPath('data.receipt', fn ($actual) => app(SemesterScheduleStateService::class)->hash($actual) === app(SemesterScheduleStateService::class)->hash($this->receipt->json('data.receipt')));
        $this->assertDatabaseCount('semester_schedules', 1);
        $this->assertDatabaseCount('semester_schedule_imports', 1);
    }
}
