<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Imports\ScheduleWorkbookParser;
use App\Services\Imports\SemesterScheduleImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesForwardOnlyMigrations;
use Tests\Support\TestDatabase;
use Tests\TestCase;

#[Group('mysql')]
#[Group('concurrency')]
class SemesterScheduleImportConcurrencyTest extends TestCase
{
    use UsesForwardOnlyMigrations;

    private function preview(): array
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);
        $path = base_path('tests/Fixtures/ScheduleImports/Приклад розкладу.xlsx');
        $template = app(ScheduleWorkbookParser::class)->parse($path);
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            Subject::factory()->for($owner)->create(['name' => $name]);
        }
        foreach (array_unique(array_column($template['rules'], 'teacher_name')) as $name) {
            Teacher::factory()->for($owner)->create(['name' => $name]);
        }
        $import = app(SemesterScheduleImportService::class)->create($owner, ['academic_period_id' => $period->id,
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator'], new UploadedFile($path, 'schedule.xlsx', null, null, true));
        $this->assertSame('ready', $import->status);

        return [$owner, $import];
    }

    private function worker(User $owner, $import): Process
    {
        $config = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/semester_import_worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => TestDatabase::name(),
            'PLANNER_TEST_DATABASE' => TestDatabase::name(), 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'SEMESTER_IMPORT_WORKER_INPUT' => json_encode(['user_id' => $owner->id, 'import_id' => $import->id, 'token' => $import->preview_token], JSON_THROW_ON_ERROR),
        ], timeout: 30);
        $process->start();
        $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, '"ready":true')));
        $this->assertTrue($process->isRunning());

        return $process;
    }

    private function outcome(Process $process): array
    {
        $this->assertSame(0, $process->wait(), $process->getOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_two_independent_confirmations_apply_once_and_replay_identical_receipt(): void
    {
        [$owner, $import] = $this->preview();
        $workers = [];
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            $workers[] = $this->worker($owner, $import);
            $workers[] = $this->worker($owner, $import);
            DB::commit();
            $first = $this->outcome($workers[0]);
            $second = $this->outcome($workers[1]);
            $this->assertFalse($first['changed']);
            // MySQL JSON object key order is not part of the receipt contract.
            $canonical = function ($value) use (&$canonical) {
                if (! is_array($value)) {
                    return $value;
                }
                $value = array_map($canonical, $value);
                if (! array_is_list($value)) {
                    ksort($value, SORT_STRING);
                }

                return $value;
            };
            $this->assertSame($canonical($first), $canonical($second));
            $this->assertDatabaseCount('semester_schedules', 1);
            $this->assertDatabaseCount('weekly_lesson_rules', 16);
            $this->assertDatabaseCount('lessons', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function test_waiting_commit_rechecks_changes_committed_while_waiting_for_owner_lock(): void
    {
        [$owner, $import] = $this->preview();
        $worker = null;
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            $worker = $this->worker($owner, $import);
            $owner->subjects()->first()->update(['code' => 'AFTER PREVIEW']);
            DB::commit();
            $result = $this->outcome($worker);
            $this->assertTrue($result['changed']);
            $this->assertNotSame($import->preview_token, $result['token']);
            $this->assertNull($result['receipt']);
            $this->assertDatabaseCount('weekly_lesson_rules', 0);
            $fresh = $import->fresh();
            $confirmed = app(SemesterScheduleImportService::class)->commit($owner, $fresh, $fresh->preview_token);
            $this->assertFalse($confirmed['changed']);
            $this->assertDatabaseCount('weekly_lesson_rules', 16);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker?->stop();
        }
    }
}
