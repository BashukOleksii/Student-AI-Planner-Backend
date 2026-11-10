<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\SemesterSchedule;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Imports\ScheduleWorkbookParser;
use App\Services\Imports\SemesterScheduleImportService;
use App\Services\Schedule\SemesterScheduleManagementService;
use App\Services\Schedule\SemesterScheduleStateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class SemesterScheduleManagementConcurrencyTest extends TestCase
{
    use UsesForwardOnlyMigrations;

    private function context(): array
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);

        return [$owner, $period];
    }

    private function schedule(User $owner, AcademicPeriod $period): array
    {
        $service = app(SemesterScheduleManagementService::class);
        $graph = $service->create($owner, ['academic_period_id' => $period->id, 'group_name' => 'ПІ-231', 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']);
        $subject = Subject::factory()->for($owner)->create();
        $graph = $service->read($owner, $graph['schedule']);
        $graph = $service->createRule($owner, $graph['schedule'], ['schedule_token' => $graph['meta']['schedule_token'],
            'weekday' => 1, 'pair_number' => 1, 'week_pattern' => 'all', 'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00',
            'subject_id' => $subject->id]);

        return [$graph['schedule'], $graph['rules']->first(), $graph['meta']['schedule_token']];
    }

    private function race(User $owner, array $inputs): array
    {
        $config = DB::connection()->getConfig();
        $workers = [];
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($inputs as $input) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/semester_management_worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
                    'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => TestDatabase::name(),
                    'PLANNER_TEST_DATABASE' => TestDatabase::name(), 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'SEMESTER_MANAGEMENT_WORKER_INPUT' => json_encode(['user_id' => $owner->id, ...$input], JSON_THROW_ON_ERROR),
                ], timeout: 30);
                $workers[] = $worker;
                $worker->start();
                $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, '"ready":true')));
                $this->assertTrue($worker->isRunning());
            }
            DB::commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $statuses[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);

            return $statuses;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function test_simultaneous_edits_apply_one_and_reject_the_stale_other(): void
    {
        [$owner, $period] = $this->context();
        [$schedule, $rule, $token] = $this->schedule($owner, $period);
        $input = ['action' => 'update', 'schedule_id' => $schedule->id, 'rule_id' => $rule->id];
        $this->assertSame([200, 409], $this->race($owner, [
            [...$input, 'values' => ['schedule_token' => $token, 'room' => 'first']],
            [...$input, 'values' => ['schedule_token' => $token, 'room' => 'second']],
        ]));
        $this->assertContains($rule->fresh()->room, ['first', 'second']);
        $this->assertDatabaseCount('weekly_lesson_rules', 1);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_simultaneous_delete_and_copy_snapshot_preserve_fk_integrity(): void
    {
        [$owner, $period] = $this->context();
        [$schedule, $rule, $token] = $this->schedule($owner, $period);
        $this->assertSame([200, 409], $this->race($owner, [
            ['action' => 'delete', 'schedule_id' => $schedule->id, 'rule_id' => $rule->id, 'values' => ['schedule_token' => $token]],
            ['action' => 'snapshot', 'schedule_id' => $schedule->id, 'values' => ['schedule_token' => $token, 'mode' => 'copy_base']],
        ]));
        $graph = app(SemesterScheduleManagementService::class)->read($owner, $schedule);
        if ($graph['rules']->isEmpty()) {
            $this->assertCount(1, $graph['suppressions']);
            $this->assertCount(0, $graph['exceptions']);
        } else {
            $this->assertCount(1, $graph['lessons']);
            $this->assertSame($rule->id, $graph['lessons']->first()->source_weekly_rule_id);
        }
    }

    public function test_simultaneous_manual_creations_reject_duplicate_period_atomically(): void
    {
        [$owner, $period] = $this->context();
        $input = ['action' => 'create', 'values' => ['academic_period_id' => $period->id, 'group_name' => 'ПІ-231',
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator']];
        $this->assertSame([200, 422], $this->race($owner, [$input, $input]));
        $this->assertDatabaseCount('semester_schedules', 1);
        $this->assertDatabaseCount('weekly_lesson_rules', 0);
    }

    public function test_management_and_import_share_owner_serialization_and_stale_detection(): void
    {
        [$owner, $period] = $this->context();
        $path = base_path('tests/Fixtures/ScheduleImports/Приклад розкладу.xlsx');
        $template = app(ScheduleWorkbookParser::class)->parse($path);
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            Subject::factory()->for($owner)->create(['name' => $name]);
        }
        foreach (array_unique(array_column($template['rules'], 'teacher_name')) as $name) {
            Teacher::factory()->for($owner)->create(['name' => $name]);
        }
        $imports = app(SemesterScheduleImportService::class);
        $options = ['academic_period_id' => $period->id, 'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator'];
        $import = $imports->create($owner, $options, new UploadedFile($path, 'schedule.xlsx', null, null, true));
        $receipt = $imports->commit($owner, $import, $import->preview_token)['import']->receipt;
        $next = $imports->create($owner, [...$options, 'first_week_parity' => 'denominator'], new UploadedFile($path, 'schedule.xlsx', null, null, true));
        $schedule = SemesterSchedule::findOrFail($receipt['semester_schedule_id']);
        $graph = app(SemesterScheduleManagementService::class)->read($owner, $schedule);
        $rule = $graph['rules']->first();
        $this->assertSame([200, 409], $this->race($owner, [
            ['action' => 'update', 'schedule_id' => $schedule->id, 'rule_id' => $rule->id,
                'values' => ['schedule_token' => $graph['meta']['schedule_token'], 'room' => 'MANUAL']],
            ['action' => 'import', 'import_id' => $next->id, 'values' => ['preview_token' => $next->preview_token]],
        ]));
        $this->assertSame(app(SemesterScheduleStateService::class)->hash($receipt), app(SemesterScheduleStateService::class)->hash($import->fresh()->receipt));
        $this->assertDatabaseCount('weekly_lesson_rules', 16);
        $this->assertNotSame($schedule->fresh()->first_week_parity === 'denominator', $rule->fresh()->room === 'MANUAL');
    }
}
