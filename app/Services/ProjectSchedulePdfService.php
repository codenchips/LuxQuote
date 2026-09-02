<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use App\Models\ProjectTender;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\Process\Process;
use Throwable;

class ProjectSchedulePdfService
{
    /**
     * @param  array<int, int>  $areaIds
     */
    public function filename(Project $project, ProjectRevision $revision, array $areaIds = []): string
    {
        return app(ProjectExportFilenameService::class)->make(
            $project,
            $revision,
            ProjectExportFilenameService::LightingSchedule,
            'pdf',
        );
    }

    /**
     * @param  array<int, int>  $areaIds
     */
    public function quoteFilename(Project $project, ProjectRevision $revision, ?ProjectTender $tender = null, array $areaIds = []): string
    {
        return app(ProjectExportFilenameService::class)->make(
            $project,
            $revision,
            ProjectExportFilenameService::ProjectQuote,
            'pdf',
        );
    }

    /**
     * @param  array<int, int>  $areaIds
     */
    public function salesforceScheduleFilename(Project $project, ProjectRevision $revision, array $areaIds = []): string
    {
        return $this->filename($project, $revision, $areaIds);
    }

    /**
     * @param  array<int, int>  $areaIds
     */
    public function salesforceQuoteFilename(Project $project, ProjectRevision $revision, ?ProjectTender $tender = null, array $areaIds = []): string
    {
        return $this->quoteFilename($project, $revision, $tender, $areaIds);
    }

    /**
     * @param  array<int, int>  $areaIds
     */
    public function content(Project $project, ProjectRevision $revision, array $areaIds = []): string
    {
        return $this->contentFromBuilder($this->builder($project, $revision, 'schedule', $areaIds));
    }

    public function contentFromBuilder(PdfBuilder $builder): string
    {
        return base64_decode($builder->base64(), true) ?: '';
    }

    /**
     * @param  array<int, int>  $areaIds
     */
    public function builder(Project $project, ProjectRevision $revision, string $documentType = 'schedule', array $areaIds = []): PdfBuilder
    {
        $areasQuery = ProjectArea::where('project_revision_id', $revision->id)
            ->with([
                'lines' => fn ($query) => $query->orderBy('sort_order')->with('product'),
            ])
            ->orderBy('sort_order');

        if ($areaIds !== []) {
            $areasQuery->whereIn('id', $areaIds);
        }

        $areas = $areasQuery->get();

        $areas = $areas
            ->map(function (ProjectArea $area) use ($documentType): ProjectArea {
                $area->setRelation(
                    'lines',
                    $area->lines
                        ->filter(fn (ProjectLine $line): bool => $this->specialLineShouldShow($line, $documentType))
                        ->values(),
                );

                return $area;
            })
            ->filter(fn (ProjectArea $area): bool => $area->lines->isNotEmpty())
            ->values();

        $generatedAt = now()->format('M d Y H:i');
        $salesEngineer = $this->salesEngineerForProject($project);
        $branchName = $this->branchNameForProject($project);

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
            'documentTitle' => $documentType === 'quote' ? 'Project Quotation' : 'Lighting Schedule',
            'showPrices' => $documentType === 'quote',
            'salesEngineerName' => $salesEngineer['name'] ?? null,
            'salesEngineerEmail' => $salesEngineer['email'] ?? $project->owner_email,
            'branchName' => $branchName,
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

    /**
     * @param  array<int, int>  $areaIds
     */
    public function quoteBuilder(Project $project, ProjectRevision $revision, array $areaIds = []): PdfBuilder
    {
        return $this->builder($project, $revision, 'quote', $areaIds);
    }

    private function specialLineShouldShow(ProjectLine $line, string $documentType): bool
    {
        $specialOrderCode = ProjectLine::specialOrderCodeFor($line->code);

        if ($specialOrderCode === null) {
            return true;
        }

        return $documentType === 'quote'
            ? $specialOrderCode->show_on_quotes
            : $specialOrderCode->show_on_schedules;
    }

    /**
     * @param  array<int, int>  $areaIds
     */
    public function quoteContent(Project $project, ProjectRevision $revision, ?ProjectTender $tender = null, bool $includeCover = true, array $areaIds = []): string
    {
        $quoteContent = $this->contentFromBuilder($this->quoteBuilder($project, $revision, $areaIds));

        $tenderBelongsToProject = $tender === null
            || (int) $tender->project_id === (int) $project->getKey();

        if (! $includeCover || ! $tenderBelongsToProject || ($tender === null && ! $project->tenders()->exists())) {
            return $quoteContent;
        }

        return $this->prependQuoteCover(
            coverContent: $this->quoteCoverContent($project, $revision, $tender),
            quoteContent: $quoteContent,
        );
    }

    public function quoteCoverContent(Project $project, ProjectRevision $revision, ?ProjectTender $tender = null): string
    {
        return $this->contentFromBuilder($this->quoteCoverBuilder($project, $revision, $tender));
    }

    public function quoteCoverBuilder(Project $project, ProjectRevision $revision, ?ProjectTender $tender = null): PdfBuilder
    {
        return Pdf::view('pdfs.quote-cover', [
            'project' => $project,
            'revision' => $revision,
            'contractor' => $this->quoteCoverContractor($project, $tender),
            'salesEngineer' => $this->salesEngineerForProject($project),
            'quoteDate' => now(),
        ])
            ->withBrowsershot(function ($browsershot): void {
                $this->configureBrowsershot($browsershot);
                $browsershot->noSandbox();
            })
            ->format('A4');
    }

    private function prependQuoteCover(string $coverContent, string $quoteContent): string
    {
        $workingDirectory = storage_path('app/private/quote-cover-merge-temp/'.Str::uuid());
        File::ensureDirectoryExists($workingDirectory);

        $coverPath = $workingDirectory.'/cover.pdf';
        $quotePath = $workingDirectory.'/quote.pdf';
        $outputPath = $workingDirectory.'/merged.pdf';

        try {
            File::put($coverPath, $coverContent);
            File::put($quotePath, $quoteContent);

            $this->assertValidPdf($coverPath, 'The quote cover PDF could not be merged.');
            $this->assertValidPdf($quotePath, 'The quote PDF could not be merged with the cover page.');
            $this->merge([$coverPath, $quotePath], $outputPath);

            return File::get($outputPath);
        } finally {
            File::deleteDirectory($workingDirectory);
        }
    }

    /**
     * @return array{name: string|null, billing_street: string|null, billing_city: string|null, billing_state: string|null, billing_postal_code: string|null, phone: string|null}
     */
    private function quoteCoverContractor(Project $project, ?ProjectTender $tender = null): array
    {
        if ($tender !== null && (int) $tender->project_id !== (int) $project->getKey()) {
            $tender = null;
        }

        $tender ??= $project->tenders()
            ->where('is_primary', true)
            ->first()
            ?? $project->tenders()->orderBy('id')->first();

        if ($tender instanceof ProjectTender) {
            $payload = $this->salesforceAccountPayloadForTender($project, $tender);
            $cachedPayload = $tender->account_payload ?? [];

            return [
                'name' => $payload['Name'] ?? $tender->account_name,
                'billing_street' => $payload['BillingStreet'] ?? $cachedPayload['BillingStreet'] ?? null,
                'billing_city' => $payload['BillingCity'] ?? $tender->billing_city ?? $cachedPayload['BillingCity'] ?? null,
                'billing_state' => $payload['BillingState'] ?? $cachedPayload['BillingState'] ?? null,
                'billing_postal_code' => $payload['BillingPostalCode'] ?? $cachedPayload['BillingPostalCode'] ?? null,
                'phone' => $payload['Phone'] ?? $cachedPayload['Phone'] ?? null,
            ];
        }

        return [
            'name' => $project->customer_name,
            'billing_street' => null,
            'billing_city' => $project->site_location,
            'billing_state' => null,
            'billing_postal_code' => null,
            'phone' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function salesforceAccountPayloadForTender(Project $project, ProjectTender $tender): array
    {
        if (blank($tender->salesforce_account_id)) {
            return $tender->account_payload ?? [];
        }

        try {
            return app(SalesforceService::class)->fetchAccountById($tender->salesforce_account_id)
                ?? $tender->account_payload
                ?? [];
        } catch (Throwable $exception) {
            Log::warning('Salesforce Account lookup failed during quote cover generation', [
                'project_id' => $project->id,
                'project_reference' => $project->reference_number,
                'salesforce_account_id' => $tender->salesforce_account_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $tender->account_payload ?? [];
        }
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

            throw new RuntimeException('The quote PDF could not be merged with the cover page.');
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

    /**
     * @return array{id: string, name: string|null, first_name?: string|null, last_name?: string|null, email: string|null, title?: string|null, mobile_phone?: string|null}|null
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

    private function branchNameForProject(Project $project): ?string
    {
        if (filled($project->branch_name)) {
            return (string) $project->branch_name;
        }

        if (blank($project->salesforce_id)) {
            return null;
        }

        try {
            return app(SalesforceService::class)->getOpportunityBranch((string) $project->salesforce_id);
        } catch (Throwable $exception) {
            Log::warning('Salesforce branch lookup failed during PDF generation', [
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
