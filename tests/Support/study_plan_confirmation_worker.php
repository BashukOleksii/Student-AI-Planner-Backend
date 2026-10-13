<?php

// Separate process/PDO connection for real MySQL contention; no migrations or config secrets in output.
use App\Exceptions\StudyPlanConflictException;
use App\Models\User;
use App\Services\Planning\StudyPlanPersistenceService;
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
try {
    $result = $app->make(StudyPlanPersistenceService::class)->confirm($owner, $input['preview_token'], $input['accept_partial'] ?? false);
    $outcome = ['status' => 'created', 'count' => $result['sessions']->count()];
} catch (StudyPlanConflictException) {
    $outcome = ['status' => 'conflict'];
} catch (ValidationException) {
    $outcome = ['status' => 'validation'];
} catch (Throwable $exception) {
    $outcome = ['status' => 'error', 'class' => $exception::class];
}
fwrite(STDOUT, json_encode($outcome)."\n");
