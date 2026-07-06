<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProjectLegalPdfService
{
    /**
     * @return array{path: string, filename: string}
     */
    public function appendLegalPage(string $documentContent, string $filename): array
    {
        $workingDirectory = storage_path('app/private/legal-merge-temp/'.Str::uuid());
        $outputDirectory = storage_path('app/private/legal-merge-outputs');
        File::ensureDirectoryExists($workingDirectory);
        File::ensureDirectoryExists($outputDirectory);

        $documentPath = $workingDirectory.'/document.pdf';
        $mergedPath = $outputDirectory.'/'.Str::uuid().'.pdf';

        try {
            File::put($documentPath, $documentContent);

            $this->assertValidPdf($documentPath, 'The generated document PDF could not be merged.');
            $this->assertValidPdf($this->legalPagePath(), 'The legal page PDF could not be merged.');
            $this->merge([$documentPath, $this->legalPagePath()], $mergedPath);

            return [
                'path' => $mergedPath,
                'filename' => $filename,
            ];
        } finally {
            File::deleteDirectory($workingDirectory);
        }
    }

    public function legalPagePath(): string
    {
        $path = (string) config('document-packs.legal_page_pdf');

        if (! File::isFile($path)) {
            throw new RuntimeException('The legal page PDF could not be found.');
        }

        return $path;
    }

    public function content(string $path): string
    {
        return File::get($path);
    }

    public function delete(string $path): void
    {
        File::delete($path);
    }

    /** @param array<int, string> $inputPaths */
    private function merge(array $inputPaths, string $outputPath): void
    {
        $arguments = [$this->qpdfBinary(), '--empty', '--pages'];

        foreach ($inputPaths as $inputPath) {
            $arguments[] = $inputPath;
            $arguments[] = '1-z';
        }

        $arguments[] = '--';
        $arguments[] = $outputPath;

        $process = new Process($arguments);
        $process->setTimeout((float) config('document-packs.process_timeout_seconds', 60));
        $process->run();

        if (! in_array($process->getExitCode(), [0, 3], true) || ! File::isFile($outputPath)) {
            File::delete($outputPath);

            throw new RuntimeException('The PDF could not be merged with the legal page.');
        }
    }

    private function assertValidPdf(string $path, string $message): void
    {
        $process = new Process([$this->qpdfBinary(), '--check', $path]);
        $process->setTimeout((float) config('document-packs.process_timeout_seconds', 60));
        $process->run();

        if (! in_array($process->getExitCode(), [0, 3], true)) {
            throw new RuntimeException($message);
        }
    }

    private function qpdfBinary(): string
    {
        return (string) config('document-packs.qpdf_binary', 'qpdf');
    }
}
