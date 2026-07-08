<?php

use App\Http\Controllers\DocumentPackController;
use App\Http\Controllers\ProjectPdfController;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPdf\Facades\Pdf;

// Filament handles all routes

Route::middleware('auth')->group(function (): void {
    Route::get('/projects/{project}/pdf/schedule', [ProjectPdfController::class, 'schedule'])
        ->name('projects.pdf.schedule');

    Route::get('/projects/{project}/pdf/quote', [ProjectPdfController::class, 'quote'])
        ->name('projects.pdf.quote');

    Route::get('/pdf-progress/{token}', [ProjectPdfController::class, 'progress'])
        ->name('pdf.progress');

    Route::get('/pdf-downloads/{token}/{filename?}', [ProjectPdfController::class, 'download'])
        ->name('pdf.downloads.show');

    Route::get('/projects/{project}/export/csv', [ProjectPdfController::class, 'csv'])
        ->name('projects.export.csv');

    Route::get('/projects/{project}/export/unpriced-csv', [ProjectPdfController::class, 'unpricedCsv'])
        ->name('projects.export.unpriced-csv');

    Route::get('/projects/{project}/document-packs/{documentPack}', DocumentPackController::class)
        ->name('projects.document-packs.download');

    Route::get('/projects/{project}/document-packs/{documentPack}/items/{documentPackItem}/file', [DocumentPackController::class, 'uploadedItem'])
        ->name('projects.document-packs.items.file');
});

Route::get('/test-pdf', function () {
    return Pdf::html('<h1 style="color: #4f46e5; font-family: sans-serif;">LuxQuote PDF Engine Working!</h1>')
        ->withBrowsershot(function ($browsershot) {
            // Docker containers require running Chrome without a sandbox layer
            $browsershot->noSandbox();
        })
        ->inline('test.pdf');
});
