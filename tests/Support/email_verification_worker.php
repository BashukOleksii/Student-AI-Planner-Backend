<?php

use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\ProfileService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\TestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = $app->make('db')->connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== TestDatabase::name()
    || $connection->selectOne('SELECT DATABASE() AS name')->name !== TestDatabase::name()) {
    throw new RuntimeException('Unsafe email verification test database.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$owner = User::findOrFail($input['owner_id']);
echo json_encode(['ready' => true, 'connection_id' => $connection->selectOne('SELECT CONNECTION_ID() AS id')->id]), "\n";
fflush(STDOUT);
try {
    if ($input['action'] === 'verify') {
        $request = Request::create($input['path'], 'POST');
        $route = $app->make('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        $app->make(EmailVerificationService::class)->confirm($owner, $request);
    } else {
        $app->make(ProfileService::class)->update($owner, ['email' => 'concurrent@example.test']);
    }
    $status = 200;
} catch (HttpExceptionInterface $exception) {
    $status = $exception->getStatusCode();
} catch (Throwable) {
    echo json_encode(['worker_error' => true]), "\n";
    exit(1);
}
echo json_encode(['status' => $status]), "\n";
