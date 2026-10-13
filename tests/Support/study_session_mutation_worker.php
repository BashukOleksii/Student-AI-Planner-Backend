<?php

// Independent PDO/process; never migrate, truncate, or print configuration secrets.
use App\Exceptions\StudyPlanConflictException;
use App\Exceptions\StudySessionConflictException;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReschedulingService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== 'student_planner_testing'
    || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'student_planner_testing') {
    throw new RuntimeException('Unsafe concurrency test database.');
}
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
CarbonImmutable::setTestNow(CarbonImmutable::parse($input['now']));
$owner = User::whereKey($input['owner_id'])->firstOrFail();
fwrite(STDOUT, json_encode(['ready' => true, 'connection_id' => $connection->selectOne('SELECT CONNECTION_ID() AS id')->id])."\n");
fflush(STDOUT);
try {
    $status = match ($input['action']) {
        'complete' => (function () use ($app, $owner, $input) {
            $app->make(StudySessionLifecycleService::class)->complete($owner, (string) $input['session_id'], 45);

            return 'completed';
        })(),
        'cancel' => (function () use ($app, $owner, $input) {
            $app->make(StudySessionLifecycleService::class)->cancel($owner, (string) $input['session_id']);

            return 'cancelled';
        })(),
        'reschedule' => (function () use ($app, $owner, $input) {
            $app->make(StudySessionReschedulingService::class)->reschedule($owner, (string) $input['session_id'],
                $input['starts_at'] ?? '2026-10-19T11:00:00Z', $input['ends_at'] ?? '2026-10-19T12:00:00Z');

            return 'created';
        })(),
        'delete' => (function () use ($app, $owner, $input) {
            $app->make(TaskService::class)->delete($owner, Task::whereKey($input['task_id'])->firstOrFail());

            return 'deleted';
        })(),
        'confirm' => (function () use ($app, $owner, $input) {
            $app->make(StudyPlanPersistenceService::class)->confirm($owner, $input['preview_token']);

            return 'confirmed';
        })(),
    };
    $outcome = ['status' => $status];
} catch (StudySessionConflictException|StudyPlanConflictException) {
    $outcome = ['status' => 'conflict'];
} catch (Throwable $exception) {
    $outcome = ['status' => 'error', 'class' => $exception::class];
}
fwrite(STDOUT, json_encode($outcome)."\n");
