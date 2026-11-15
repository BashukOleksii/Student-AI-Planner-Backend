<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningPreferenceService;
use App\Services\Planning\StudyAvailabilityService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningPersistenceService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReschedulingService;
use App\Services\Schedule\LessonService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithMysqlWorkers;
use Tests\Support\TestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('mysql')]
class CompactStudyReplanningConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithMysqlWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_two_blocked_confirmations_apply_exactly_one_multi_source_batch(): void
    {
        [$owner, $task, $source] = $this->fixture();
        $second = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-19 12:00:00', 'ends_at' => '2026-10-19 13:00:00']);
        $preview = $this->preview($owner);
        $workers = [];
        DB::beginTransaction();
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        try {
            foreach (range(1, 2) as $unused) {
                $worker = $this->worker($owner, $preview['preview_token']);
                $workers[] = $worker;
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
            DB::commit();
            $statuses = array_map(fn ($worker) => $this->outcome($worker)['status'], $workers);
            sort($statuses);
            $this->assertSame(['applied', 'conflict'], $statuses);
            $this->assertDatabaseCount('study_sessions', 4);
            foreach ([$source, $second] as $original) {
                $this->assertSame(StudySessionStatus::Rescheduled, $original->fresh()->status);
                $this->assertSame(1, StudySession::where('rescheduled_from_session_id', $original->id)->count());
            }
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    #[DataProvider('mutations')]
    public function test_waiter_regenerates_after_actual_cooperating_mutation_commits(string $mutation): void
    {
        [$owner, $task, $source, $lesson, $window] = $this->fixture();
        $child = null;
        if ($mutation === 'subtask delete') {
            $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 240]);
            $source->update(['subtask_id' => $child->id]);
        }
        if ($mutation === 'complete') {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T09:59:00Z'));
        }
        $preview = $this->preview($owner);
        $worker = null;
        $spawn = function () use ($owner, $preview, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, $preview['preview_token'], ['now' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z')]);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        };
        $model = match ($mutation) {
            'lesson' => Lesson::class,
            'preferences' => PlanningPreference::class,
            'availability' => StudyAvailabilityWindow::class,
            'task delete' => Task::class,
            'subtask delete' => Subtask::class,
            default => StudySession::class,
        };
        if (str_ends_with($mutation, 'delete')) {
            $model::deleted($spawn);
        } elseif ($mutation === 'plan' || $mutation === 'preferences') {
            $model::created($spawn);
        } else {
            $model::updated($spawn);
        }
        try {
            match ($mutation) {
                'cancel' => app(StudySessionLifecycleService::class)->cancel($owner, (string) $source->id),
                'complete' => (function () use ($owner, $source) {
                    $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
                    app(StudySessionLifecycleService::class)->complete($owner, (string) $source->id, 45);
                })(),
                'reschedule' => app(StudySessionReschedulingService::class)->reschedule($owner, (string) $source->id, '2026-10-19T13:00:00Z', '2026-10-19T14:00:00Z'),
                'task delete' => app(TaskService::class)->delete($owner, $task),
                'subtask delete' => app(SubtaskService::class)->delete($owner, $task, $child),
                'lesson' => app(LessonService::class)->update($owner, $lesson, ['starts_at' => '2026-10-19T09:00:00Z', 'ends_at' => '2026-10-19T10:00:00Z']),
                'preferences' => app(PlanningPreferenceService::class)->replace($owner, ['min_session_minutes' => null, 'max_session_minutes' => 30, 'preferred_break_minutes' => null, 'max_daily_study_minutes' => null, 'max_weekly_study_minutes' => null]),
                'availability' => app(StudyAvailabilityService::class)->update($owner, $window, ['starts_at' => '09:30:00']),
                'plan' => (function () use ($owner) {
                    $plan = app(StudyPlanPersistenceService::class)->preview($owner, '2026-10-19', '2026-10-19');
                    app(StudyPlanPersistenceService::class)->confirm($owner, $plan['preview_token']);
                })(),
            };
            $this->assertNotNull($worker);
            $this->assertSame('conflict', $this->outcome($worker)['status']);
            $this->assertSame($mutation === 'reschedule' ? 1 : 0, StudySession::where('rescheduled_from_session_id', $source->id)->count());
        } finally {
            $model::flushEventListeners();
            $worker?->stop();
        }
    }

    public static function mutations(): array
    {
        return [0 => ['cancel']];
    }

    public function test_stage_c_waiter_observes_committed_e2_replacements(): void
    {
        [$owner] = $this->fixture();
        $preview = $this->preview($owner);
        $plan = app(StudyPlanPersistenceService::class)->preview($owner, '2026-10-19', '2026-10-19');
        $worker = null;
        StudySession::updated(function () use ($owner, $plan, &$worker): void {
            if ($worker === null) {
                $worker = $this->worker($owner, $plan['preview_token'], ['action' => 'plan']);
                $this->assertWaitingOnOwner($this->readyConnection($worker));
            }
        });
        try {
            app(StudyReplanningPersistenceService::class)->confirm($owner, $preview['preview_token']);
            $this->assertSame('conflict', $this->outcome($worker)['status']);
            $this->assertDatabaseCount('study_sessions', 2);
        } finally {
            StudySession::flushEventListeners();
            $worker?->stop();
        }
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 240]);
        $source = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $lesson = Lesson::factory()->for($owner)->create(['starts_at' => '2026-10-19 10:00:00', 'ends_at' => '2026-10-19 11:00:00']);
        $window = StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);

        return [$owner, $task, $source, $lesson, $window];
    }

    private function preview(User $owner): array
    {
        return app(StudyReplanningPersistenceService::class)->preview($owner, '2026-10-19', '2026-10-19');
    }

    private function worker(User $owner, string $token, array $parameters = []): Process
    {
        $config = DB::connection()->getConfig();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/study_replanning_confirmation_worker.php')], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => TestDatabase::name(), 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => $config['unix_socket'] ?? '']);
        $worker->setInput(json_encode(['owner_id' => $owner->id, 'preview_token' => $token, 'now' => '2026-10-19T08:00:00.500000Z', ...$parameters], JSON_THROW_ON_ERROR));
        $worker->setTimeout(15);
        $worker->start();

        return $worker;
    }
}
