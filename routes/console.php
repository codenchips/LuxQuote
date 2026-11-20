<?php

use App\Services\DatabaseImportLock;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$guardDatabaseImport = function (Event $event): void {
    $locks = null;
    $event->before(function () use (&$locks): void {
        $locks = app(DatabaseImportLock::class)->acquire(false);
        if ($locks === null) {
            throw new RuntimeException('Scheduled task skipped because a database import is active.');
        }
    })->after(function () use (&$locks): void {
        if ($locks !== null) {
            app(DatabaseImportLock::class)->release($locks);
            $locks = null;
        }
    });
};

$guardDatabaseImport(Schedule::command('app:prune-generated-pdfs')
    ->hourlyAt(23)
    ->withoutOverlapping(10));

$guardDatabaseImport(Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('01:51')
    ->withoutOverlapping(10));

$guardDatabaseImport(Schedule::command('app:prune-activity-logs')
    ->dailyAt('01:41')
    ->withoutOverlapping(30));

$guardDatabaseImport(Schedule::command('app:sync-reporting-events')
    ->dailyAt('01:31')
    ->withoutOverlapping(30));
