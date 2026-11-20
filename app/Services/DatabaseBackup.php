<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackup
{
    public function createDump(): string
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Database export requires a MySQL connection.');
        }
        $config = $connection->getConfig();
        $directory = storage_path('app/private/database-exports');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the backup directory.');
        }
        $path = tempnam($directory, 'luxquote-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the backup file.');
        }
        if (($output = fopen($path, 'wb')) === false) {
            @unlink($path);
            throw new RuntimeException('Unable to create the backup file.');
        }
        chmod($path, 0600);
        $arguments = ['mysqldump', '--single-transaction', '--routines', '--triggers', '--events', '--no-tablespaces', '--add-drop-table', '--user='.(string) $config['username']];
        if (filled($config['unix_socket'] ?? null)) {
            $arguments[] = '--socket='.$config['unix_socket'];
        } else {
            $arguments[] = '--host='.(string) $config['host'];
            $arguments[] = '--port='.(string) $config['port'];
        }
        $arguments[] = '--';
        $arguments[] = (string) $config['database'];
        $process = new Process($arguments, null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
        $process->setTimeout(600);
        $process->disableOutput();
        try {
            $process->mustRun(function (string $type, string $buffer) use ($output): void {
                if ($type === Process::OUT && fwrite($output, $buffer) !== strlen($buffer)) {
                    throw new RuntimeException('Unable to write the database backup.');
                }
            });
            fclose($output);
            if (filesize($path) === 0) {
                throw new RuntimeException('The database backup is empty.');
            }

            return $path;
        } catch (Throwable $exception) {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($path);
            throw $exception;
        }
    }
}
