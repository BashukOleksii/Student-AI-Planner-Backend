<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\SemesterScheduleImport;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\TestCase;

class SemesterScheduleImportPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, UsesForwardOnlyMigrations;

    private function import($period): SemesterScheduleImport
    {
        return SemesterScheduleImport::create(['user_id' => $period->user_id, 'academic_period_id' => $period->id,
            'original_filename' => 'schedule.xlsx', 'file_hash' => str_repeat('a', 64), 'parity_anchor_on' => '2026-09-07',
            'first_week_parity' => 'numerator', 'status' => 'committed', 'template' => null, 'review' => [],
            'preview_token' => str_repeat('b', 64), 'receipt' => [], 'committed_at' => '2026-11-10 12:00:00.123456'])->refresh();
    }

    public function test_mysql_enforces_owner_identity_provenance_and_receipt_constraints(): void
    {
        $period = AcademicPeriod::factory()->create();
        $import = $this->import($period);
        $otherPeriod = AcademicPeriod::factory()->create();
        $foreign = $this->import($otherPeriod);
        $this->assertMysqlRejects(fn () => DB::table('semester_schedule_imports')->where('id', $import->id)->update(['user_id' => $otherPeriod->user_id]), 1452);
        foreach ([['status' => 'other'], ['parity_anchor_on' => '2026-09-08'], ['first_week_parity' => 'all'], ['receipt' => null], ['committed_at' => null]] as $invalid) {
            $this->assertMysqlRejects(fn () => DB::table('semester_schedule_imports')->where('id', $import->id)->update($invalid), 3819);
        }
        $owner = User::find($period->user_id);
        $schedule = $owner->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'ПІ-231',
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']);
        $values = ['weekday' => 1, 'pair_number' => 1, 'pair_label' => '1-2', 'week_pattern' => 'all',
            'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00', 'subject_id' => Subject::factory()->for($owner)->create()->id,
            'teacher_id' => null, 'room' => null];
        $rule = $schedule->weeklyLessonRules()->create([...$values, 'user_id' => $owner->id,
            'semester_schedule_import_id' => $import->id, 'import_key' => '1:1:all', 'import_baseline' => $values])->refresh();
        $this->assertTrue($rule->semesterScheduleImport->is($import));
        $this->assertSame([$rule->id], $import->weeklyLessonRules->modelKeys());
        $this->assertSame([$import->id], $period->semesterScheduleImports->modelKeys());
        $this->assertMysqlRejects(fn () => DB::table('weekly_lesson_rules')->where('id', $rule->id)->update(['semester_schedule_import_id' => $foreign->id]), 1452);
        $this->assertMysqlRejects(fn () => DB::table('weekly_lesson_rules')->where('id', $rule->id)->update(['import_baseline' => null]), 3819);
        $attributes = $rule->getAttributes();
        unset($attributes['id']);
        $attributes['pair_number'] = 2;
        $this->assertMysqlRejects(fn () => DB::table('weekly_lesson_rules')->insert($attributes), 1062, 'weekly_rule_import_key_unique');
        $this->assertMysqlRejects(fn () => DB::table('semester_schedule_imports')->where('id', $import->id)->delete(), 1451);
        $this->assertSame('123456', $import->committed_at->format('u'));
    }

    public function test_additive_migration_preserves_legacy_data_and_refuses_history_downgrade(): void
    {
        $migration = require database_path('migrations/2026_11_10_000002_create_semester_schedule_imports.php');
        $management = require database_path('migrations/2026_11_10_000003_add_semester_schedule_management_protections.php');
        $management->down();
        $migration->down();
        $lesson = Lesson::factory()->create();
        $before = $lesson->fresh()->getRawOriginal();
        $migration->up();
        $management->up();
        $this->assertSame($before, $lesson->fresh()->getRawOriginal());
        $this->assertTrue(Schema::hasColumn('weekly_lesson_rules', 'import_baseline'));
        $this->assertDatabaseCount('semester_schedule_imports', 0);
        $this->import(AcademicPeriod::factory()->create());
        try {
            $migration->down();
            $this->fail('Retained import history must never be silently removed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward migration', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('semester_schedule_imports'));
        $this->assertTrue(Schema::hasColumn('weekly_lesson_rules', 'import_key'));
        $this->assertSame($before, $lesson->fresh()->getRawOriginal());
    }
}
