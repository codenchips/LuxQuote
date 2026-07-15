<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectRevision;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Throwable;

class ProjectSchedulePdfService
{
    public function filename(Project $project, ProjectRevision $revision): string
    {
        return $this->documentFilename('Lighting Schedule', $project, $revision);
    }

    public function quoteFilename(Project $project, ProjectRevision $revision): string
    {
        return $this->documentFilename('Lighting Quote', $project, $revision);
    }

    public function content(Project $project, ProjectRevision $revision): string
    {
        return $this->contentFromBuilder($this->builder($project, $revision));
    }

    public function contentFromBuilder(PdfBuilder $builder): string
    {
        return base64_decode($builder->base64(), true) ?: '';
    }

    public function builder(Project $project, ProjectRevision $revision, string $documentType = 'schedule'): PdfBuilder
    {
        $areas = ProjectArea::where('project_revision_id', $revision->id)
            ->with([
                'lines' => fn ($query) => $query->orderBy('sort_order')->with('product'),
            ])
            ->orderBy('sort_order')
            ->get();

        $generatedAt = now()->format('M d Y H:i');
        $salesEngineer = $this->salesEngineerForProject($project);

        $footerHtml = '<style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            .f {
                width: 100%; padding: 3mm 14mm 2mm;
                font-family: Arial, Helvetica, sans-serif; font-size: 6.5pt; color: #666;
                display: flex; justify-content: space-between; align-items: flex-start;
                border-top: 0.75pt solid #d1d5db;
            }
            .blocks { display: flex; gap: 8mm; }
            .block { line-height: 1.3; }
            .pg { font-size: 8pt; color: #333; white-space: nowrap; align-self: flex-end; }
        </style>
        <div class="f">
            <div class="blocks">
                <div class="block">Tamlite Technical<br>Stafford Park 12<br>Telford, Shropshire,<br>TF3 3BJ</div>
                <div class="block">T: +44 (0)1952 292441<br>E: technical@tamlite.co.uk<br>W: www.tamlite.co.uk</div>
                <div class="block">Generated on: '.$generatedAt.'<br>Produced by Tamlite Lighting</div>
            </div>
            <div class="pg">Page <span class="pageNumber"></span> of <span class="totalPages"></span></div>
        </div>';

        return Pdf::view('pdfs.schedule', [
            'project' => $project->load('user'),
            'revision' => $revision,
            'areas' => $areas,
            'documentType' => $documentType,
            'documentTitle' => $documentType === 'quote' ? 'Lighting Quote' : 'Lighting Schedule',
            'showPrices' => $documentType === 'quote',
            'salesEngineerName' => $salesEngineer['name'] ?? null,
            'salesEngineerEmail' => $salesEngineer['email'] ?? $project->owner_email,
        ])
            ->withBrowsershot(function ($browsershot) use ($footerHtml): void {
                $this->configureBrowsershot($browsershot);
                $browsershot->noSandbox();
                $browsershot->showBrowserHeaderAndFooter();
                $browsershot->headerHtml('<p>Header</p>');
                $browsershot->footerHtml($footerHtml);
            })
            ->format('A4');
    }

    public function quoteBuilder(Project $project, ProjectRevision $revision): PdfBuilder
    {
        return $this->builder($project, $revision, 'quote');
    }

    public function quoteContent(Project $project, ProjectRevision $revision): string
    {
        return $this->contentFromBuilder($this->quoteBuilder($project, $revision));
    }

    private function documentFilename(string $title, Project $project, ProjectRevision $revision): string
    {
        return collect([
            $title,
            $project->reference_number ?? 'proj-'.$project->id,
            $revision->label(),
            now()->format('Ymd-His'),
        ])
            ->map(fn (string $part): string => $this->filenamePart($part))
            ->implode('-').'.pdf';
    }

    private function filenamePart(string $part): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $part), '-');
    }

    /**
     * @return array{id: string, name: string|null, email: string|null}|null
     */
    private function salesEngineerForProject(Project $project): ?array
    {
        if (blank($project->salesforce_id)) {
            return null;
        }

        try {
            return app(SalesforceService::class)->getOpportunityOwner((string) $project->salesforce_id);
        } catch (Throwable $exception) {
            Log::warning('Salesforce owner lookup failed during PDF generation', [
                'project_id' => $project->id,
                'project_reference' => $project->reference_number,
                'salesforce_id' => $project->salesforce_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function configureBrowsershot(object $browsershot): void
    {
        $tempPath = config('laravel-pdf.browsershot.temp_path') ?: storage_path('app/browsershot');

        File::ensureDirectoryExists($tempPath);

        $browsershot->setCustomTempPath($tempPath);

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
