<?php

namespace App\Console\Commands;

use App\Services\ProjectLegalPdfService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('app:production-health-check {--no-pdf : Skip Browsershot and qpdf checks} {--pdf-only : Run only the production PDF runtime checks}')]
#[Description('Run production-safe health checks for the app, database, storage, cache, and PDF runtime.')]
class ProductionHealthCheck extends Command
{
    /** @var array<int, string> */
    private array $failures = [];

    private ?string $probePdfContent = null;

    public function handle(ProjectLegalPdfService $legalPdfService): int
    {
        $this->info('LuxQuote production health check');

        $this->check('Application booted', fn (): bool => app()->isBooted());

        if (! $this->option('pdf-only')) {
            $this->check('Database connection', function (): bool {
                DB::select('select 1 as health_check');

                return true;
            });
            $this->check('Cache round trip', function (): bool {
                $key = 'health-check:'.gethostname();
                Cache::put($key, 'ok', now()->addMinute());
                $passed = Cache::get($key) === 'ok';
                Cache::forget($key);

                return $passed;
            });
        }

        $this->check('Storage is writable', function (): bool {
            $path = storage_path('app/health-check.txt');
            File::put($path, now()->toIso8601String());
            $passed = File::isFile($path);
            File::delete($path);

            return $passed;
        });
        $this->check('Legal PDF exists', function () use ($legalPdfService): bool {
            return File::isFile($legalPdfService->legalPagePath());
        });

        if (! $this->option('no-pdf')) {
            $this->check('qpdf binary works', function (): bool {
                $process = new Process([$this->qpdfBinary(), '--version']);
                $process->setTimeout((float) config('document-packs.process_timeout_seconds', 60));
                $process->mustRun();

                return str_contains(strtolower($process->getOutput()), 'qpdf');
            });

            $this->check('Browsershot renders a PDF', function (): bool {
                $content = $this->probePdfContent();

                return str_starts_with($content, '%PDF');
            });

            $this->check('Generated PDF merges with legal page', function () use ($legalPdfService): bool {
                $merged = $legalPdfService->appendLegalPage($this->probePdfContent(), 'health-check.pdf');

                try {
                    $this->assertValidPdf($merged['path']);

                    return true;
                } finally {
                    $legalPdfService->delete($merged['path']);
                }
            });
        }

        if ($this->failures !== []) {
            $this->newLine();
            $this->error('Health check failed.');

            foreach ($this->failures as $failure) {
                $this->line('- '.$failure);
            }

            return self::FAILURE;
        }

        $this->info('Health check passed.');

        return self::SUCCESS;
    }

    private function check(string $label, callable $callback): void
    {
        try {
            if ($callback() !== true) {
                throw new RuntimeException('Check returned false.');
            }

            $this->line('<info>PASS</info> '.$label);
        } catch (Throwable $exception) {
            $this->line('<error>FAIL</error> '.$label.' - '.$exception->getMessage());
            $this->failures[] = $label.': '.$exception->getMessage();
        }
    }

    private function probePdfContent(): string
    {
        return $this->probePdfContent ??= $this->renderProbePdf();
    }

    private function renderProbePdf(): string
    {
        $tempPath = (string) (config('laravel-pdf.browsershot.temp_path') ?: storage_path('app/browsershot'));

        File::ensureDirectoryExists($tempPath);

        $builder = Pdf::html('<h1>LuxQuote PDF health check</h1>')
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
            });

        return base64_decode($builder->base64(), true) ?: '';
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

    private function assertValidPdf(string $path): void
    {
        $process = new Process([$this->qpdfBinary(), '--check', $path]);
        $process->setTimeout((float) config('document-packs.process_timeout_seconds', 60));
        $process->mustRun();
    }

    private function qpdfBinary(): string
    {
        return (string) config('document-packs.qpdf_binary', 'qpdf');
    }
}
