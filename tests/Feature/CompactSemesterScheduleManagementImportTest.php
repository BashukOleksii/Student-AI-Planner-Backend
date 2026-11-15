<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use App\Services\Imports\ScheduleWorkbookParser;
use App\Services\Schedule\SemesterScheduleStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('workflow')]
#[Group('mysql')]
class CompactSemesterScheduleManagementImportTest extends TestCase
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
        return $this->postJson('/api/semester-schedule-imports', ['academic_period_id' => $this->period->id, 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator', 'schedule_file' => new UploadedFile($path ?? $this->fixture(), 'schedule.xlsx', null, null, true)]);
    }

    private function confirm(TestResponse $preview): TestResponse
    {
        return $this->postJson('/api/semester-schedule-imports/'.$preview->json('data.id').'/commit', ['confirmed' => true, 'preview_token' => $preview->json('data.preview_token')]);
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

    public function test_manual_edit_is_visible_until_confirmation_and_then_replaced(): void
    {
        $rule = $this->firstRule();
        $baseline = $rule->import_baseline;
        $this->mutate('PATCH', '/weekly-rules/'.$rule->id, ['room' => 'MANUAL'])->assertOk();
        $this->assertTrue($rule->fresh()->is_manually_managed);
        $this->assertSame($baseline, $rule->fresh()->import_baseline);
        $preview = $this->preview()->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.preview.counts.delete', 16)->assertJsonPath('data.preview.counts.create', 16);
        $this->assertSame('MANUAL', $rule->fresh()->room);
        $this->confirm($preview)->assertOk();
        $this->assertNull($rule->fresh());
        $this->assertDatabaseMissing('weekly_lesson_rules', ['room' => 'MANUAL']);
        $this->assertFalse($this->firstRule()->is_manually_managed);
    }

    public function test_manual_deletion_requires_fresh_confirmation_then_restores_slot_and_removes_snapshot(): void
    {
        $rule = $this->firstRule();
        $this->mutate('PUT', '/date-exceptions/2026-09-07', ['mode' => 'copy_base'])->assertOk();
        $waiting = $this->preview()->assertJsonPath('data.status', 'ready');
        $this->mutate('DELETE', '/weekly-rules/'.$rule->id, ['confirmed' => true])->assertOk();
        $stale = $this->confirm($waiting)->assertConflict()->assertJsonPath('data.status', 'ready');
        $this->assertNotEmpty($stale->json('data.preview.impact.deleted_suppressed_slots'));
        $this->assertDatabaseCount('weekly_lesson_rules', 15);
        $this->assertDatabaseCount('schedule_date_exceptions', 1);
        $this->confirm($this->original)->assertOk()->assertJsonPath('data.receipt', fn ($actual) => app(SemesterScheduleStateService::class)->hash($actual) === app(SemesterScheduleStateService::class)->hash($this->receipt->json('data.receipt')));
        $this->assertDatabaseCount('weekly_lesson_rules', 15);
        $review = $this->postJson('/api/semester-schedule-imports/'.$waiting->json('data.id').'/revalidate')
            ->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertDatabaseCount('semester_schedule_suppressed_slots', 1);
        $this->confirm($review)->assertOk();
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
        $this->assertDatabaseCount('schedule_date_exceptions', 0);
        $this->assertDatabaseCount('schedule_date_exception_lessons', 0);
        $this->assertDatabaseCount('semester_schedule_suppressed_slots', 0);
        $this->getJson($this->url('/week/2026-09-14'))->assertJsonCount(15, 'data');
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_imported_identity_move_and_deletion_do_not_block_full_replacement(): void
    {
        $rule = $this->firstRule();
        $this->mutate('PATCH', '/weekly-rules/'.$rule->id, ['weekday' => 5, 'pair_number' => 255, 'starts_at_local' => '20:00:00', 'ends_at_local' => '21:00:00'])->assertOk();
        $this->assertSame($rule->import_key, $rule->fresh()->import_key);
        $this->assertSame($rule->import_baseline, $rule->fresh()->import_baseline);
        $this->preview()->assertJsonPath('data.status', 'ready');
        $this->mutate('DELETE', '/weekly-rules/'.$rule->id, ['confirmed' => true])->assertOk();
        $this->assertDatabaseCount('semester_schedule_suppressed_slots', 2);
        $preview = $this->preview()->assertJsonPath('data.status', 'ready');
        $this->assertCount(2, $preview->json('data.preview.impact.deleted_suppressed_slots'));
        $this->confirm($preview)->assertOk();
        $this->assertDatabaseCount('semester_schedule_suppressed_slots', 0);
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
    }
}
