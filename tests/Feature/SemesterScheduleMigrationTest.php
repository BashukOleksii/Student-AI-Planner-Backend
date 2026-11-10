<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\SemesterSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\TestCase;

class SemesterScheduleMigrationTest extends TestCase
{
    use UsesForwardOnlyMigrations;

    public function test_additive_upgrade_preserves_legacy_rows_and_refuses_destructive_downgrade(): void
    {
        $migration = require database_path('migrations/2026_11_10_000001_create_semester_schedule_foundation.php');
        // Remove the later FK overlay before testing the original foundation in isolation.
        $overlay = require database_path('migrations/2026_11_10_000002_create_semester_schedule_imports.php');
        $management = require database_path('migrations/2026_11_10_000003_add_semester_schedule_management_protections.php');
        $management->down();
        $overlay->down();
        $migration->down();
        $lesson = Lesson::factory()->create();
        $before = $lesson->fresh()->getRawOriginal();
        $migration->up();
        $this->assertSame($before, $lesson->fresh()->getRawOriginal());
        foreach (['semester_schedules', 'weekly_lesson_rules', 'schedule_date_exceptions', 'schedule_date_exception_lessons'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }
        $checks = DB::select("SELECT CONSTRAINT_NAME AS name, ENFORCED AS enforced
            FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
            AND TABLE_NAME IN ('semester_schedules', 'weekly_lesson_rules', 'schedule_date_exceptions', 'schedule_date_exception_lessons')");
        $this->assertCount(10, $checks);
        $this->assertSame(['YES'], array_values(array_unique(array_column($checks, 'enforced'))));
        $overlay->up();
        $management->up();
        $period = $lesson->user->academicPeriods()->create(['name' => 'Legacy period without institution',
            'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']);
        $schedule = SemesterSchedule::create(['user_id' => $lesson->user_id, 'academic_period_id' => $period->id,
            'group_name' => 'ПІ-231', 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']);
        $this->assertTrue($period->semesterSchedule->is($schedule));
        try {
            $migration->down();
            $this->fail('Populated semester tables must never be silently dropped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward migration', $exception->getMessage());
        }
        $this->assertModelExists($schedule);
        $this->assertSame($before, $lesson->fresh()->getRawOriginal());
        $this->assertTrue(collect(Schema::getIndexes('academic_periods'))->contains(
            fn ($index) => $index['unique'] && $index['columns'] === ['id', 'user_id'],
        ));
    }
}
