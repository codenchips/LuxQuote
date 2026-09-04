<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:prune-generated-pdfs')
    ->hourlyAt(23)
    ->withoutOverlapping(10);

Schedule::command('app:prune-activity-logs')
    ->dailyAt('01:41')
    ->withoutOverlapping(30);

Schedule::command('app:sync-reporting-events')
    ->dailyAt('01:31')
    ->withoutOverlapping(30);
