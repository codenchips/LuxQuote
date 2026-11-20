<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseImport
{
    /** @param array<int, resource>|null $requestLocks */
    public function import(UploadedFile $file, int $actorId, ?array $requestLocks = null): string
    {
        if (DB::connection()->getDriverName() !== 'mysql' || config('queue.default') !== 'database') {
            throw new RuntimeException('Import requires MySQL and the database queue.');
        }
        $lockManager = app(DatabaseImportLock::class);
        $locks = $requestLocks ?? $lockManager->acquire(true);
        if ($locks === null || count($locks) !== 2) {
            throw new RuntimeException('The database is busy. Wait for active requests or the deployment to finish and try again.');
        }
        $maintenanceStarted = false;
        $restoreStarted = false;
        $backup = null;
        $database = (string) DB::connection()->getDatabaseName();
        try {
            $this->validateDump($file->getRealPath());
            if (app()->isDownForMaintenance() || Artisan::call('down', ['--retry' => 60]) !== 0) {
                throw new RuntimeException('Could not enter maintenance mode.');
            }
            $maintenanceStarted = true;
            sleep(3);
            $queueConnection = config('queue.connections.database.connection') ?: config('database.default');
            if (DB::connection($queueConnection)->table(config('queue.connections.database.table', 'jobs'))->whereNotNull('reserved_at')->exists()) {
                throw new RuntimeException('A queued job is still running. Wait for it to finish and try again.');
            }
            $backup = app(DatabaseBackup::class)->createDump();
            Log::warning('Database import starting', ['database' => $database, 'user_id' => $actorId, 'backup' => $backup]);
            $input = fopen($file->getRealPath(), 'rb');
            if ($input === false) {
                throw new RuntimeException('The uploaded SQL file could not be read.');
            }
            try {
                $config = DB::connection()->getConfig();
                $arguments = ['mysql', '--binary-mode', '--local-infile=0', '--user='.(string) $config['username']];
                if (filled($config['unix_socket'] ?? null)) {
                    $arguments[] = '--socket='.$config['unix_socket'];
                } else {
                    $arguments[] = '--host='.(string) $config['host'];
                    $arguments[] = '--port='.(string) $config['port'];
                }
                $arguments[] = '--database='.$database;
                $process = new Process($arguments, null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')], $input, 1800);
                $process->disableOutput();
                $restoreStarted = true;
                $process->mustRun();
            } finally {
                fclose($input);
            }
            DB::purge();
            $knownMigrations = array_keys(app('migrator')->getMigrationFiles(database_path('migrations')));
            $appliedMigrations = DB::table('migrations')->pluck('migration')->all();
            if (array_diff($knownMigrations, $appliedMigrations) !== []) {
                throw new RuntimeException('The restored database has pending migrations and must be reviewed before reopening.');
            }
            app(ScheduledMaintenance::class)->invalidateExistingSessions();
            if (Artisan::call('up') !== 0) {
                throw new RuntimeException('The restore completed but maintenance mode could not be cleared.');
            }
            $maintenanceStarted = false;
            Log::warning('Database import completed', ['database' => $database, 'user_id' => $actorId, 'backup' => $backup]);

            return $backup;
        } catch (Throwable $exception) {
            if ($maintenanceStarted && ! $restoreStarted) {
                Artisan::call('up');
            }
            Log::error('Database import failed', ['database' => $database, 'user_id' => $actorId, 'backup' => $backup, 'restore_started' => $restoreStarted, 'error' => $exception->getMessage()]);
            if ($restoreStarted) {
                throw new RuntimeException('The import failed after the restore started. LuxQuote remains in maintenance mode. Contact the server operator to recover the pre-import backup: '.basename((string) $backup), previous: $exception);
            }

            throw $exception;
        } finally {
            if ($requestLocks === null) {
                $lockManager->release($locks);
            }
        }
    }

    private function validateDump(string $path): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The uploaded SQL file could not be read.');
        }
        $created = [];
        $dropped = [];
        try {
            while (($line = fgets($stream, 1048576)) !== false) {
                if (preg_match('/^\s*(?:USE\s|(?:CREATE|DROP)\s+(?:DATABASE|SCHEMA)\b)/i', $line)) {
                    throw new RuntimeException('SQL dumps that select, create or delete databases are not supported.');
                }
                if (preg_match('/^CREATE TABLE\s+`((?:``|[^`])+)`\s*\(/i', $line, $match)) {
                    $created[] = str_replace('``', '`', $match[1]);
                }
                if (preg_match('/^DROP TABLE IF EXISTS\s+`((?:``|[^`])+)`/i', $line, $match)) {
                    $dropped[] = str_replace('``', '`', $match[1]);
                }
            }
        } finally {
            fclose($stream);
        }
        $current = Schema::getTableListing(DB::connection()->getDatabaseName(), false);
        sort($current);
        sort($created);
        sort($dropped);
        if ($current === [] || $created !== $current || $dropped !== $current) {
            throw new RuntimeException('The dump must contain a complete backup of the current database tables, including DROP TABLE statements. Found '.count($created).' table definitions and '.count($dropped).' drop statements; expected '.count($current).'.');
        }
    }
}
