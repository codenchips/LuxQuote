<?php

use App\Http\Controllers\DocumentPackController;
use App\Http\Controllers\PdfGenerationController;
use App\Http\Controllers\ProjectPdfController;
use App\Http\Controllers\ResourceFileController;
use App\Models\Project;
use App\Models\ProjectLock;
use App\Models\ProjectPresence;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Support\Facades\Route;

// Filament handles all routes

Route::get('/resources/{resourceFile}/file', ResourceFileController::class)
    ->middleware(FilamentAuthenticate::class)
    ->name('resource-files.view');

Route::middleware('auth')->group(function (): void {
    Route::get('/projects/{project}/pdf/schedule', [ProjectPdfController::class, 'schedule'])
        ->name('projects.pdf.schedule');
    Route::post('/projects/{project}/pdf/schedule', [ProjectPdfController::class, 'queueSchedule'])
        ->name('projects.pdf.schedule.queue');

    Route::get('/projects/{project}/pdf/quote', [ProjectPdfController::class, 'quote'])
        ->name('projects.pdf.quote');
    Route::post('/projects/{project}/pdf/quote', [ProjectPdfController::class, 'queueQuote'])
        ->name('projects.pdf.quote.queue');

    Route::post('/projects/{project}/pdf/quote/prepare', [ProjectPdfController::class, 'prepareQuote'])
        ->name('projects.pdf.quote.prepare');
    Route::post('/projects/{project}/pdf/quote/prepare/queue', [ProjectPdfController::class, 'queuePreparedQuote'])
        ->name('projects.pdf.quote.prepare.queue');

    Route::post('/projects/{project}/pdf/quote/datasheets/prepare', [ProjectPdfController::class, 'prepareQuoteDatasheets'])
        ->name('projects.pdf.quote.datasheets.prepare');
    Route::post('/projects/{project}/pdf/quote/datasheets/prepare/queue', [ProjectPdfController::class, 'queueQuoteDatasheets'])
        ->name('projects.pdf.quote.datasheets.prepare.queue');

    Route::post('/projects/{project}/pdf/quote/zip', [ProjectPdfController::class, 'zipPreparedQuotes'])
        ->name('projects.pdf.quote.zip');

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
    Route::post('/projects/{project}/document-packs/{documentPack}', [DocumentPackController::class, 'queue'])
        ->name('projects.document-packs.queue');
    Route::post('/projects/{project}/document-packs/{documentPack}/zip', [DocumentPackController::class, 'zip'])
        ->name('projects.document-packs.zip');

    Route::get('/pdf-generations/{pdfGeneration}', [PdfGenerationController::class, 'show'])
        ->name('pdf-generations.show');
    Route::post('/pdf-generations/{pdfGeneration}/retry', [PdfGenerationController::class, 'retry'])
        ->name('pdf-generations.retry');

    Route::get('/projects/{project}/document-packs/{documentPack}/items/{documentPackItem}/file', [DocumentPackController::class, 'uploadedItem'])
        ->name('projects.document-packs.items.file');

    Route::get('/projects/{project}/document-pack-resources/{resourceFile}/file', [DocumentPackController::class, 'resource'])
        ->name('projects.document-packs.resources.file');

    Route::get('/projects/{project}/document-pack-template-items/{documentPackTemplateItem}/file', [DocumentPackController::class, 'templateItem'])
        ->name('projects.document-pack-templates.items.file');

    Route::get('/projects/{project}/document-pack-standard-legal-page/file', [DocumentPackController::class, 'standardLegalPage'])
        ->name('projects.document-packs.standard-legal-page.file');

    Route::post('/projects/{project}/lock/release', function (Project $project): void {
        ProjectLock::query()
            ->where('project_id', $project->id)
            ->where('user_id', auth()->id())
            ->delete();

        ProjectPresence::query()
            ->where('project_id', $project->id)
            ->where('user_id', auth()->id())
            ->delete();
    })->name('projects.lock.release');
});
