<?php

// Destructive verification is restricted to this workflow's new hosted MySQL service.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
foreach ([
    'CI' => 'true', 'GITHUB_ACTIONS' => 'true', 'RUNNER_ENVIRONMENT' => 'github-hosted',
    'GITHUB_REPOSITORY' => 'BashukOleksii/Student-AI-Planner-Backend',
    'GITHUB_WORKFLOW' => 'Backend verification', 'GITHUB_JOB' => 'verify',
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'student_planner_testing',
] as $key => $expected) {
    if (getenv($key) !== $expected) {
        throw new RuntimeException('Unsafe CI migration context: '.$key);
    }
}
$container = getenv('DISPOSABLE_CI_MYSQL_CONTAINER_ID');
if (! is_string($container) || ! preg_match('/\A[0-9a-f]{64}\z/', $container)
    || realpath(getenv('GITHUB_WORKSPACE') ?: '') !== $root
    || ! in_array(getenv('GITHUB_EVENT_NAME'), ['push', 'pull_request', 'workflow_dispatch'], true)) {
    throw new RuntimeException('Expected this repository checkout and a disposable hosted service.');
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || $db->getDriverName() !== 'mysql' || $db->getConfig('driver') !== 'mysql'
    || $db->getConfig('database') !== 'student_planner_testing'
    || $db->getConfig('host') !== '127.0.0.1' || (string) $db->getConfig('port') !== '3306'
    || ! empty($db->getConfig('unix_socket')) || ! empty($db->getConfig('url'))) {
    throw new RuntimeException('Unsafe resolved Laravel CI database configuration.');
}
$row = $db->selectOne('SELECT DATABASE() AS database_name, @@hostname AS server_hostname, VERSION() AS version, @@transaction_isolation AS isolation_level, @@performance_schema AS instrumentation');
if ($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
    || $row->database_name !== 'student_planner_testing'
    // Docker's default hostname binds the live connection to this job's service ID.
    || $row->server_hostname !== substr($container, 0, 12)
    || ! str_starts_with($row->version, '8.4.') || $row->isolation_level !== 'REPEATABLE-READ'
    || (int) $row->instrumentation !== 1) {
    throw new RuntimeException('Expected the disposable MySQL 8.4 service database and lock instrumentation.');
}
foreach (['data_locks', 'data_lock_waits', 'threads'] as $table) {
    $db->selectOne('SELECT COUNT(*) AS total FROM performance_schema.'.$table);
}
if ($db->select('SHOW TABLES') !== []) {
    throw new RuntimeException('Expected an empty, newly created CI database before migration verification.');
}
echo json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;

// Stay on the validated Laravel connection; never invoke a production downgrade.
foreach (['migrate', 'migrate:fresh', 'migrate'] as $command) {
    $exitCode = Artisan::call($command, ['--force' => true, '--no-interaction' => true]);
    echo Artisan::output();
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}
