<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

#[Signature('app:prune-generated-pdfs {--dry-run : Report eligible files and directories without deleting them}')]
#[Description('Remove abandoned generated PDF files after their retention period.')]
class PruneGeneratedPdfs extends Command
{
    private const UuidPdfPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.pdf$/i';

    private const DownloadPattern = '/^[A-Za-z0-9]{48}\.(?:pdf|zip)$/';

    private const UuidDirectoryPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $root = rtrim((string) config('document-packs.generated_pdf_cleanup.root', storage_path('app')), DIRECTORY_SEPARATOR);
        $outputRetentionMinutes = max(1, (int) config('document-packs.generated_pdf_cleanup.output_retention_hours', 24) * 60);
        $downloadRetentionMinutes = max(1, (int) config('document-packs.generated_pdf_cleanup.download_retention_minutes', 60));
        $tempRetentionMinutes = max(1, (int) config('document-packs.generated_pdf_cleanup.temp_retention_hours', 24) * 60);

        $removedFiles = 0;
        $removedDirectories = 0;
        $reclaimedBytes = 0;
        $failures = 0;

        foreach ($this->outputDirectories($root, $outputRetentionMinutes, $downloadRetentionMinutes) as $target) {
            $result = $this->pruneFiles($target['path'], $target['pattern'], $target['retention_minutes'], $dryRun);
            $removedFiles += $result['removed'];
            $reclaimedBytes += $result['bytes'];
            $failures += $result['failures'];
        }

        foreach ($this->temporaryDirectories($root) as $path) {
            $result = $this->pruneDirectories($path, $tempRetentionMinutes, $dryRun);
            $removedDirectories += $result['removed'];
            $reclaimedBytes += $result['bytes'];
            $failures += $result['failures'];
        }

        $verb = $dryRun ? 'Would remove' : 'Removed';
        $this->info(sprintf(
            '%s %d generated file(s) and %d temporary director%s (%s).',
            $verb,
            $removedFiles,
            $removedDirectories,
            $removedDirectories === 1 ? 'y' : 'ies',
            $this->formatBytes($reclaimedBytes),
        ));

        if ($failures > 0) {
            $this->error("{$failures} generated storage item(s) could not be inspected or removed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{path: string, pattern: string, retention_minutes: int}>
     */
    private function outputDirectories(string $root, int $outputRetentionMinutes, int $downloadRetentionMinutes): array
    {
        return [
            ['path' => $root.'/private/legal-merge-outputs', 'pattern' => self::UuidPdfPattern, 'retention_minutes' => $outputRetentionMinutes],
            ['path' => $root.'/private/datasheet-merge-outputs', 'pattern' => self::UuidPdfPattern, 'retention_minutes' => $outputRetentionMinutes],
            ['path' => $root.'/private/document-pack-outputs', 'pattern' => self::UuidPdfPattern, 'retention_minutes' => $outputRetentionMinutes],
            ['path' => $root.'/pdf-downloads', 'pattern' => self::DownloadPattern, 'retention_minutes' => $downloadRetentionMinutes],
        ];
    }

    /** @return array<int, string> */
    private function temporaryDirectories(string $root): array
    {
        return [
            $root.'/private/legal-merge-temp',
            $root.'/private/quote-cover-merge-temp',
            $root.'/private/datasheet-merge-temp',
            $root.'/private/document-pack-temp',
        ];
    }

    /**
     * @return array{removed: int, bytes: int, failures: int}
     */
    private function pruneFiles(string $directory, string $pattern, int $retentionMinutes, bool $dryRun): array
    {
        $result = ['removed' => 0, 'bytes' => 0, 'failures' => 0];

        if (! File::isDirectory($directory)) {
            return $result;
        }

        $oldestAllowedTimestamp = now()->subMinutes($retentionMinutes)->getTimestamp();

        try {
            $files = File::files($directory);
        } catch (Throwable $exception) {
            $this->warn("Could not inspect {$directory}: {$exception->getMessage()}");

            return ['removed' => 0, 'bytes' => 0, 'failures' => 1];
        }

        foreach ($files as $file) {
            try {
                if (preg_match($pattern, $file->getFilename()) !== 1 || $file->getMTime() >= $oldestAllowedTimestamp) {
                    continue;
                }

                $size = $file->getSize();

                if (! $dryRun && ! File::delete($file->getPathname()) && File::exists($file->getPathname())) {
                    throw new \RuntimeException('Deletion returned false.');
                }

                $result['removed']++;
                $result['bytes'] += $size;
            } catch (Throwable $exception) {
                $result['failures']++;
                $this->warn("Could not prune {$file->getPathname()}: {$exception->getMessage()}");
            }
        }

        return $result;
    }

    /**
     * @return array{removed: int, bytes: int, failures: int}
     */
    private function pruneDirectories(string $directory, int $retentionMinutes, bool $dryRun): array
    {
        $result = ['removed' => 0, 'bytes' => 0, 'failures' => 0];

        if (! File::isDirectory($directory)) {
            return $result;
        }

        $oldestAllowedTimestamp = now()->subMinutes($retentionMinutes)->getTimestamp();

        try {
            $directories = File::directories($directory);
        } catch (Throwable $exception) {
            $this->warn("Could not inspect {$directory}: {$exception->getMessage()}");

            return ['removed' => 0, 'bytes' => 0, 'failures' => 1];
        }

        foreach ($directories as $childDirectory) {
            try {
                if (preg_match(self::UuidDirectoryPattern, basename($childDirectory)) !== 1
                    || is_link($childDirectory)
                    || File::lastModified($childDirectory) >= $oldestAllowedTimestamp) {
                    continue;
                }

                $size = collect(File::allFiles($childDirectory))->sum(fn ($file): int => $file->getSize());

                if (! $dryRun && ! File::deleteDirectory($childDirectory) && File::isDirectory($childDirectory)) {
                    throw new \RuntimeException('Deletion returned false.');
                }

                $result['removed']++;
                $result['bytes'] += $size;
            } catch (Throwable $exception) {
                $result['failures']++;
                $this->warn("Could not prune {$childDirectory}: {$exception->getMessage()}");
            }
        }

        return $result;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
