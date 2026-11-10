<?php

use App\Models\User;
use App\Services\Imports\SemesterScheduleImportService;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\TestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = $app->make('db')->connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== TestDatabase::name()
    || $connection->selectOne('SELECT DATABASE() AS name')->name !== TestDatabase::name()) {
    throw new RuntimeException('Unsafe semester import worker database.');
}
$input = json_decode(getenv('SEMESTER_IMPORT_WORKER_INPUT'), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user_id']);
$import = $user->semesterScheduleImports()->findOrFail($input['import_id']);
echo json_encode(['ready' => true]), "\n";
fflush(STDOUT);
try {
    $result = $app->make(SemesterScheduleImportService::class)->commit($user, $import, $input['token']);
    echo json_encode(['changed' => $result['changed'], 'status' => $result['import']->status,
        'token' => $result['import']->preview_token, 'receipt' => $result['import']->receipt], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    echo json_encode(['worker_error' => true]), "\n";
    exit(1);
}
