<?php

use App\Models\User;
use App\Services\Imports\SemesterScheduleImportService;
use App\Services\Schedule\SemesterScheduleManagementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\TestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = $app->make('db')->connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== TestDatabase::name()
    || $connection->selectOne('SELECT DATABASE() AS name')->name !== TestDatabase::name()) {
    throw new RuntimeException('Unsafe semester management worker database.');
}
$input = json_decode(getenv('SEMESTER_MANAGEMENT_WORKER_INPUT'), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user_id']);
$schedule = isset($input['schedule_id']) ? $user->semesterSchedules()->findOrFail($input['schedule_id']) : null;
echo json_encode(['ready' => true]), "\n";
fflush(STDOUT);
try {
    $service = $app->make(SemesterScheduleManagementService::class);
    $result = match ($input['action']) {
        'update' => $service->updateRule($user, $schedule, $input['rule_id'], $input['values']),
        'delete' => $service->deleteRule($user, $schedule, $input['rule_id'], $input['values']),
        'snapshot' => $service->replaceDate($user, $schedule, '2026-09-07', $input['values']),
        'create' => $service->create($user, $input['values']),
        'import' => $app->make(SemesterScheduleImportService::class)->commit($user,
            $user->semesterScheduleImports()->findOrFail($input['import_id']), $input['values']['preview_token']),
    };
    echo json_encode(['status' => ($result['changed'] ?? false) ? 409 : 200]), "\n";
} catch (ConflictHttpException) {
    echo json_encode(['status' => 409]), "\n";
} catch (ValidationException) {
    echo json_encode(['status' => 422]), "\n";
} catch (Throwable) {
    echo json_encode(['worker_error' => true]), "\n";
    exit(1);
}
