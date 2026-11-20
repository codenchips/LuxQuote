<?php

use App\Http\Middleware\EnforceScheduledMaintenance;
use App\Http\Middleware\LockDatabaseDuringImport;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->prepend(SecurityHeaders::class);
        $middleware->web(append: [EnforceScheduledMaintenance::class]);
        $middleware->web(prepend: [LockDatabaseDuringImport::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
