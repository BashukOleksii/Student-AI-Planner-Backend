<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\TestCase;

#[Group('mysql')]
class SemesterScheduleReadConsistencyTest extends TestCase
{
    use UsesForwardOnlyMigrations;

    public function test_week_keeps_one_read_snapshot_when_another_connection_commits_mid_read(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);
        $schedule = $owner->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'ПІ-231',
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']);
        $subject = Subject::factory()->for($owner)->create(['name' => 'Before']);
        $fields = ['user_id' => $owner->id, 'pair_number' => 4, 'pair_label' => '7-8', 'starts_at_local' => '13:20:00',
            'ends_at_local' => '14:40:00', 'subject_id' => $subject->id];
        $rule = $schedule->weeklyLessonRules()->create([...$fields, 'weekday' => 2, 'week_pattern' => 'all', 'room' => 'Base before']);
        $exception = $schedule->dateExceptions()->create(['user_id' => $owner->id, 'occurs_on' => '2026-09-12',
            'kind' => 'working_saturday', 'copied_weekday' => 5]);
        $snapshot = $exception->lessons()->create([...$fields, 'semester_schedule_id' => $schedule->id,
            'source_weekly_rule_id' => $rule->id, 'room' => 'Snapshot before']);

        config(['database.connections.semester_writer' => config('database.connections.'.DB::getDefaultConnection())]);
        $writer = DB::connection('semester_writer');
        $this->assertNotSame(DB::getPdo(), $writer->getPdo());
        $armed = true;
        DB::listen(function (QueryExecuted $event) use (&$armed, $writer, $owner, $subject, $rule, $snapshot): void {
            if (! $armed || $event->connectionName !== DB::getDefaultConnection() || ! str_contains($event->sql, 'from `users`')) {
                return;
            }
            $armed = false;
            $this->assertSame(1, DB::transactionLevel());
            // This independent connection represents a concurrent committed writer.
            $writer->transaction(function () use ($writer, $owner, $subject, $rule, $snapshot): void {
                $writer->table('users')->where('id', $owner->id)->update(['timezone' => 'UTC']);
                $writer->table('subjects')->where('id', $subject->id)->update(['name' => 'After']);
                $writer->table('weekly_lesson_rules')->where('id', $rule->id)->update(['room' => 'Base after']);
                $writer->table('schedule_date_exception_lessons')->where('id', $snapshot->id)->update(['room' => 'Snapshot after']);
            });
        });
        try {
            $url = '/api/semester-schedules/'.$schedule->id.'/week/2026-09-13';
            $this->actingAs($owner, 'web')->getJson($url)->assertOk()
                ->assertJsonPath('meta.timezone', 'Europe/Kyiv')->assertJsonPath('data.*.subject.name', ['Before', 'Before'])
                ->assertJsonPath('data.*.room', ['Base before', 'Snapshot before'])
                ->assertJsonPath('data.0.starts_at', '2026-09-08T13:20:00+03:00');
            $this->assertFalse($armed);
            $this->getJson($url)->assertOk()->assertJsonPath('meta.timezone', 'UTC')
                ->assertJsonPath('data.*.subject.name', ['After', 'After'])->assertJsonPath('data.*.room', ['Base after', 'Snapshot after'])
                ->assertJsonPath('data.0.starts_at', '2026-09-08T13:20:00+00:00');
        } finally {
            DB::purge('semester_writer');
        }
    }
}
