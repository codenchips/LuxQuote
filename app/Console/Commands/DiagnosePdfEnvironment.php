<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

#[Signature('app:diagnose-pdf-environment')]
#[Description('Diagnose the server runtime used to generate PDFs.')]
class DiagnosePdfEnvironment extends Command
{
    public function handle(): int
    {
        $tempPath = (string) (config('laravel-pdf.browsershot.temp_path') ?: storage_path('app/browsershot'));

        $this->info('PDF environment');
        $this->line('Driver: '.(string) config('laravel-pdf.driver', 'browsershot'));
        $this->line('Node binary: '.((string) config('laravel-pdf.browsershot.node_binary') ?: 'auto'));
        $this->line('NPM binary: '.((string) config('laravel-pdf.browsershot.npm_binary') ?: 'auto'));
        $this->line('Chrome path: '.((string) config('laravel-pdf.browsershot.chrome_path') ?: 'auto'));
        $this->line('Node modules path: '.($this->nodeModulesPath() ?? 'auto'));
        $this->line('Temp path: '.$tempPath);

        try {
            File::ensureDirectoryExists($tempPath);

            Pdf::html('<h1>PDF diagnostics</h1>')
                ->withBrowsershot(function ($browsershot) use ($tempPath): void {
                    $browsershot->setCustomTempPath($tempPath);
                    $browsershot->noSandbox();

                    if (filled(config('laravel-pdf.browsershot.node_binary'))) {
                        $browsershot->setNodeBinary((string) config('laravel-pdf.browsershot.node_binary'));
                    }

                    if (filled(config('laravel-pdf.browsershot.npm_binary'))) {
                        $browsershot->setNpmBinary((string) config('laravel-pdf.browsershot.npm_binary'));
                    }

                    if (filled(config('laravel-pdf.browsershot.chrome_path'))) {
                        $browsershot->setChromePath((string) config('laravel-pdf.browsershot.chrome_path'));
                    }

                    $nodeModulesPath = $this->nodeModulesPath();

                    if ($nodeModulesPath !== null) {
                        $browsershot->setNodeModulePath($nodeModulesPath);
                    }
                })
                ->base64();
        } catch (Throwable $exception) {
            $this->error('PDF render failed: '.$exception->getMessage());
            $this->line($exception->getTraceAsString());

            return self::FAILURE;
        }

        $this->info('PDF render succeeded.');

        return self::SUCCESS;
    }

    private function nodeModulesPath(): ?string
    {
        $configuredPath = config('laravel-pdf.browsershot.node_modules_path');

        if (filled($configuredPath)) {
            return (string) $configuredPath;
        }

        $localPath = base_path('node_modules');

        return is_dir($localPath) ? $localPath : null;
    }
}
