<?php

namespace App\Services\Demo;

use App\Exceptions\DemoEnvironmentException;
use Illuminate\Support\Facades\DB;

class DemoDatabaseGuard
{
    public function inspect(bool $requireApproval = true): array
    {
        if (! app()->environment('local') || config('database.default') !== 'mysql') {
            throw new DemoEnvironmentException('Demo generation requires the local environment and the default MySQL connection.');
        }
        if ($requireApproval && config('demo.enabled') !== true) {
            throw new DemoEnvironmentException('Demo generation has not been explicitly enabled.');
        }
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql'
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || $connection->getConfig('read') || $connection->getConfig('write')) {
            throw new DemoEnvironmentException('Use a single approved loopback MySQL connection.');
        }
        $row = $connection->selectOne('SELECT DATABASE() AS name, @@server_uuid AS server_uuid, @@hostname AS hostname, @@port AS port', [], false);
        if (! is_string($row->name) || $row->name === '' || $row->name !== $connection->getDatabaseName()) {
            throw new DemoEnvironmentException('The actual selected database does not match the configured database.');
        }
        if ($requireApproval && ($row->name !== config('demo.database')
            || ! is_string(config('demo.server_uuid')) || ! hash_equals(config('demo.server_uuid'), $row->server_uuid))) {
            throw new DemoEnvironmentException('The selected MySQL server and database are not approved for demo generation.');
        }
        if ($requireApproval && (config('mail.default') !== 'array' || config('queue.default') !== 'sync')) {
            throw new DemoEnvironmentException('Demo generation requires MAIL_MAILER=array and QUEUE_CONNECTION=sync.');
        }

        return ['environment' => 'local', 'connection' => 'mysql', 'database' => $row->name,
            'server_uuid' => $row->server_uuid, 'server_hostname' => $row->hostname, 'port' => $row->port];
    }
}
