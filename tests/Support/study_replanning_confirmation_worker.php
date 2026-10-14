<?php

// Independent guarded MySQL connection/process; no migrations or fixture cleanup.
use App\Exceptions\StudyPlanConflictException;
use App\Exceptions\StudySessionConflictException;
use App\Models\User;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningPersistenceService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
// Simulate elapsed application time specifically AFTER a real lock wait resolves.
if (isset($input['now_after_lock'])) {
    DB::listen(function ($query) use ($input): void {
        if (str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'for update')) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($input['now_after_lock']));
        }
    });
}
try {
    if (($input['action'] ?? 'replan') === 'plan') {
        $app->make(StudyPlanPersistenceService::class)->confirm($owner, $input['preview_token'], true);
    } else {
        $app->make(StudyReplanningPersistenceService::class)->confirm($owner, $input['preview_token'], true, true);
    }
    $outcome = ['status' => 'applied'];
} catch (StudyPlanConflictException|StudySessionConflictException) {
    $outcome = ['status' => 'conflict'];
} catch (ValidationException) {
    $outcome = ['status' => 'invalid'];
} catch (Throwable $exception) {
    $outcome = ['status' => 'error', 'class' => $exception::class];
}
fwrite(STDOUT, json_encode($outcome)."\n");
