<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\SemesterSchedule;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\TestCase;

#[Group('mysql')]
#[Group('migration')]
class SemesterScheduleManagementPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, UsesForwardOnlyMigrations;

    private function schedule(): SemesterSchedule
    {
        $period = AcademicPeriod::factory()->create();

        return SemesterSchedule::create(['user_id' => $period->user_id, 'academic_period_id' => $period->id,
            'group_name' => 'ПІ-231', 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']);
    }

    public function test_mysql_enforces_suppression_ownership_identity_and_valid_slot_shape(): void
    {
        $schedule = $this->schedule();
        $slot = $schedule->suppressedSlots()->create(['user_id' => $schedule->user_id, 'weekday' => 1, 'pair_number' => 1, 'week_pattern' => 'all']);
        $this->assertTrue($slot->semesterSchedule->is($schedule));
        $this->assertSame($schedule->user_id, $slot->user->id);
        $this->assertMysqlRejects(fn () => $slot->update(['user_id' => User::factory()->create()->id]), 1452);
        foreach ([['weekday' => 0], ['weekday' => 6], ['pair_number' => 0], ['week_pattern' => 'odd']] as $values) {
            $this->assertMysqlRejects(fn () => DB::table($slot->getTable())->where('id', $slot->id)->update($values), 3819);
        }
        $this->assertMysqlRejects(fn () => $schedule->suppressedSlots()->create(['user_id' => $schedule->user_id, 'weekday' => 1,
            'pair_number' => 1, 'week_pattern' => 'all']), 1062, 'semester_suppressed_slot_unique');
        $this->assertMysqlRejects(fn () => DB::table('semester_schedules')->where('id', $schedule->id)->delete(), 1451);
        $rule = $schedule->weeklyLessonRules()->create(['user_id' => $schedule->user_id, 'weekday' => 2, 'pair_number' => 1,
            'week_pattern' => 'all', 'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00',
            'subject_id' => Subject::factory()->for($schedule->user)->create()->id]);
        $this->assertFalse($rule->refresh()->is_manually_managed);
        $this->assertMysqlRejects(fn () => DB::table('weekly_lesson_rules')->where('id', $rule->id)->update(['is_manually_managed' => 2]), 3819, 'weekly_rule_manual_check');
    }

    public function test_forward_migration_preserves_rows_and_refuses_removing_manual_protections(): void
    {
        $migration = require database_path('migrations/2026_11_10_000003_add_semester_schedule_management_protections.php');
        $lesson = Lesson::factory()->create();
        $legacy = $lesson->refresh()->getRawOriginal();
        $schedule = $this->schedule();
        $rule = $schedule->weeklyLessonRules()->create(['user_id' => $schedule->user_id, 'weekday' => 1, 'pair_number' => 1,
            'week_pattern' => 'all', 'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00',
            'subject_id' => Subject::factory()->for($schedule->user)->create()->id])->refresh();
        $before = $rule->getRawOriginal();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('weekly_lesson_rules', 'is_manually_managed'));
        $migration->up();
        $after = $rule->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
        $this->assertSame($legacy, $lesson->fresh()->getRawOriginal());
        $schedule->management_revision = 1;
        $schedule->save();
        try {
            $migration->down();
            $this->fail('Revision history must not be discarded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward migration', $exception->getMessage());
        }
        $schedule->management_revision = 0;
        $schedule->save();
        $rule->update(['is_manually_managed' => true]);
        try {
            $migration->down();
            $this->fail('Permanent manual flags must not be lost.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward migration', $exception->getMessage());
        }
        $rule->update(['is_manually_managed' => false]);
        $schedule->suppressedSlots()->create(['user_id' => $schedule->user_id, 'weekday' => 2, 'pair_number' => 1, 'week_pattern' => 'all']);
        try {
            $migration->down();
            $this->fail('Intentional deletions must not be lost.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forward migration', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('weekly_lesson_rules', 'is_manually_managed'));
        $this->assertDatabaseCount('semester_schedule_suppressed_slots', 1);
        $this->assertSame($legacy, $lesson->fresh()->getRawOriginal());
    }
}
