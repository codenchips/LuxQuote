<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneGeneratedPdfsTest extends TestCase
{
    private string $cleanupRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupRoot = storage_path('framework/testing/generated-pdf-cleanup-'.Str::uuid());
        config([
            'document-packs.generated_pdf_cleanup.root' => $this->cleanupRoot,
            'document-packs.generated_pdf_cleanup.output_retention_hours' => 24,
            'document-packs.generated_pdf_cleanup.download_retention_minutes' => 60,
            'document-packs.generated_pdf_cleanup.temp_retention_hours' => 24,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cleanupRoot);

        parent::tearDown();
    }

    public function test_command_removes_only_expired_generated_storage(): void
    {
        $oldLegalPdf = $this->generatedPdf('private/legal-merge-outputs', now()->subHours(25)->getTimestamp());
        $freshLegalPdf = $this->generatedPdf('private/legal-merge-outputs', now()->subHours(23)->getTimestamp());
        $oldDatasheetPdf = $this->generatedPdf('private/datasheet-merge-outputs', now()->subHours(25)->getTimestamp());
        $oldDocumentPackPdf = $this->generatedPdf('private/document-pack-outputs', now()->subHours(25)->getTimestamp());
        $oldDownload = $this->file('pdf-downloads/'.str_repeat('A', 48).'.zip', now()->subMinutes(61)->getTimestamp());
        $freshDownload = $this->file('pdf-downloads/'.str_repeat('B', 48).'.pdf', now()->subMinutes(59)->getTimestamp());
        $unexpectedFile = $this->file('private/legal-merge-outputs/keep-me.txt', now()->subYear()->getTimestamp());
        $persistentUpload = $this->file('private/document-packs/23/customer-document.pdf', now()->subYear()->getTimestamp());
        $oldTempDirectory = $this->temporaryDirectory('private/legal-merge-temp', now()->subHours(25)->getTimestamp());
        $freshTempDirectory = $this->temporaryDirectory('private/datasheet-merge-temp', now()->subHours(23)->getTimestamp());

        $this->artisan('app:prune-generated-pdfs')
            ->expectsOutputToContain('Removed 4 generated file(s) and 1 temporary directory')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($oldLegalPdf);
        $this->assertFileDoesNotExist($oldDatasheetPdf);
        $this->assertFileDoesNotExist($oldDocumentPackPdf);
        $this->assertFileDoesNotExist($oldDownload);
        $this->assertDirectoryDoesNotExist($oldTempDirectory);
        $this->assertFileExists($freshLegalPdf);
        $this->assertFileExists($freshDownload);
        $this->assertFileExists($unexpectedFile);
        $this->assertFileExists($persistentUpload);
        $this->assertDirectoryExists($freshTempDirectory);
    }

    public function test_dry_run_reports_expired_storage_without_removing_it(): void
    {
        $oldLegalPdf = $this->generatedPdf('private/legal-merge-outputs', now()->subHours(25)->getTimestamp());
        $oldTempDirectory = $this->temporaryDirectory('private/quote-cover-merge-temp', now()->subHours(25)->getTimestamp());

        $this->artisan('app:prune-generated-pdfs', ['--dry-run' => true])
            ->expectsOutputToContain('Would remove 1 generated file(s) and 1 temporary directory')
            ->assertSuccessful();

        $this->assertFileExists($oldLegalPdf);
        $this->assertDirectoryExists($oldTempDirectory);
    }

    public function test_cleanup_is_registered_with_the_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('app:prune-generated-pdfs')
            ->assertSuccessful();
    }

    private function generatedPdf(string $directory, int $modifiedAt): string
    {
        return $this->file($directory.'/'.Str::uuid().'.pdf', $modifiedAt);
    }

    private function file(string $relativePath, int $modifiedAt): string
    {
        $path = $this->cleanupRoot.'/'.$relativePath;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'generated output');
        touch($path, $modifiedAt);

        return $path;
    }

    private function temporaryDirectory(string $parentDirectory, int $modifiedAt): string
    {
        $path = $this->cleanupRoot.'/'.$parentDirectory.'/'.Str::uuid();
        File::ensureDirectoryExists($path);
        File::put($path.'/partial.pdf', 'partial output');
        touch($path.'/partial.pdf', $modifiedAt);
        touch($path, $modifiedAt);

        return $path;
    }
}
