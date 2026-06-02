<?php

use App\Http\Controllers\ProjectPdfController;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPdf\Facades\Pdf;

// Filament handles all routes

Route::middleware('auth')->group(function (): void {
    Route::get('/projects/{project}/pdf/schedule', [ProjectPdfController::class, 'schedule'])
        ->name('projects.pdf.schedule');
});

Route::get('/test-pdf', function () {
    return Pdf::html('<h1 style="color: #4f46e5; font-family: sans-serif;">LuxQuote PDF Engine Working!</h1>')
        ->withBrowsershot(function ($browsershot) {
            // Docker containers require running Chrome without a sandbox layer
            $browsershot->noSandbox();
        })
        ->inline('test.pdf');
});
