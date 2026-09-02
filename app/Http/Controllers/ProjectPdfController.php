<?php

namespace App\Http\Controllers;

use App\Enums\ProjectRevisionStatus;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\ProjectTender;
use App\Services\PdfDownloadUrlService;
use App\Services\ProjectDatasheetPdfService;
use App\Services\ProjectExportFilenameService;
use App\Services\ProjectLegalPdfService;
use App\Services\ProjectSchedulePdfService;
use App\Services\SalesforcePdfUploadTracker;
use App\Services\SalesforcePushControl;
use App\Services\SalesforceService;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProjectPdfController extends Controller
{
    /**
     * Generate and download the lighting schedule PDF for a project revision.
     */
    public function schedule(Request $request, Project $project): Response
    {
        $this->authorizeProjectAccess($request, $project);
        abort_unless($request->user()->can('output.produce-unpriced-schedule'), 403);

        $user = $request->user();
        $revision = $this->resolveRevision($request, $project);
        $areaIds = $this->resolveSelectedAreaIds($request, $revision);

        $pdf = app(ProjectSchedulePdfService::class);
        $filename = $pdf->filename($project, $revision, $areaIds);
        $builder = $pdf->builder($project, $revision, 'schedule', $areaIds);
        $legalPdf = $this->legalPdf(
            pdfContent: fn (): string => $pdf->contentFromBuilder($builder),
            filename: $filename,
        );
        $salesforceNotification = null;

        try {
            $filename = $legalPdf['filename'];
            $pdfContent = app(ProjectLegalPdfService::class)->content($legalPdf['path']);

            $datasheetPdf = $this->datasheetPdf(
                request: $request,
                project: $project,
                revision: $revision,
                pdfContent: fn (): string => $pdfContent,
                filename: $filename,
                areaIds: $areaIds,
            );

            if ($datasheetPdf !== null) {
                app(ProjectLegalPdfService::class)->delete($legalPdf['path']);
                $filename = $datasheetPdf['filename'];
                $pdfContent = app(ProjectDatasheetPdfService::class)->content($datasheetPdf['path']);
            }

            if ($this->shouldUploadSchedulePdfToSalesforce($request, $project)) {
                $salesforceNotification = $this->uploadPdfToSalesforce(
                    project: $project,
                    revision: $revision,
                    filename: $pdf->salesforceScheduleFilename($project, $revision, $areaIds),
                    pdfContent: $pdfContent,
                    documentLabel: 'Lighting Schedule',
                    documentType: 'schedule',
                    fingerprintHash: app(SalesforcePdfUploadTracker::class)->fingerprint(
                        $project,
                        $revision,
                        'schedule',
                        false,
                        $request->boolean('include_datasheets'),
                        false,
                        null,
                        $areaIds,
                    ),
                );
            }

            ActivityLog::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'action_type' => 'schedule_pdf.generated',
                'user_email_snapshot' => $user->email,
                'project_name_snapshot' => $project->name,
                'revision_number' => $revision->revision_number,
                'payload' => $this->pdfActivityPayload(
                    filename: $filename,
                    revision: $revision,
                    includeDatasheets: $request->boolean('include_datasheets'),
                    areaIds: $areaIds,
                ) + [
                    'filename' => $filename,
                ],
            ]);

            if ($datasheetPdf !== null) {
                return $this->respondWithPdf($request, $datasheetPdf, $salesforceNotification);
            }

            return $this->respondWithPdf($request, $legalPdf, $salesforceNotification);
        } catch (Throwable $exception) {
            app(ProjectLegalPdfService::class)->delete($legalPdf['path']);

            throw $exception;
        }
    }

    /**
     * Generate and download the priced quote PDF for a project revision.
     */
    public function quote(Request $request, Project $project): Response
    {
        $this->authorizeProjectAccess($request, $project);
        abort_unless(
            $request->user()->can('pricing.view') && $request->user()->can('output.produce-quote'),
            403,
        );

        $revision = $this->resolveRevision($request, $project);
        $areaIds = $this->resolveSelectedAreaIds($request, $revision);

        abort_unless(
            $revision->validated && $revision->status === ProjectRevisionStatus::Approved,
            403,
            'Quote PDF requires validation passed and quote approved.',
        );

        $pdf = app(ProjectSchedulePdfService::class);
        $tender = $this->resolveQuoteTender($request, $project);
        $includeCover = $this->shouldIncludeQuoteCover($request, $tender);
        $includeLegalPage = $request->boolean('include_legal_page', true);
        $salesforceNotification = null;
        $quotePdf = $this->quotePdf(
            request: $request,
            project: $project,
            revision: $revision,
            tender: $tender,
            includeCover: $includeCover,
            includeLegalPage: $includeLegalPage,
            areaIds: $areaIds,
        );

        try {
            if ($this->shouldUploadPdfToSalesforce($request, $project)) {
                $salesforceNotification = $this->uploadPdfToSalesforce(
                    project: $project,
                    revision: $revision,
                    filename: $pdf->salesforceQuoteFilename($project, $revision, $includeCover ? $tender : null, $areaIds),
                    pdfContent: $quotePdf['content'],
                    documentLabel: 'Lighting Quote',
                    documentType: $this->quoteSalesforceDocumentType($tender, $includeCover),
                    fingerprintHash: app(SalesforcePdfUploadTracker::class)->fingerprint(
                        $project,
                        $revision,
                        'quote',
                        true,
                        $request->boolean('include_datasheets'),
                        $includeCover,
                        $includeCover ? $tender : null,
                        $areaIds,
                        $includeLegalPage,
                    ),
                );
            }

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'project_id' => $project->id,
                'action_type' => 'quote_pdf.generated',
                'user_email_snapshot' => $request->user()->email,
                'project_name_snapshot' => $project->name,
                'revision_number' => $revision->revision_number,
                'payload' => $this->pdfActivityPayload(
                    filename: $quotePdf['pdf']['filename'],
                    revision: $revision,
                    includeDatasheets: $request->boolean('include_datasheets'),
                    areaIds: $areaIds,
                    tender: $tender,
                    includeCover: $includeCover,
                    includeLegalPage: $includeLegalPage,
                ) + [
                    'filename' => $quotePdf['pdf']['filename'],
                ],
            ]);

            $project->markQuoted($revision);

            return $this->respondWithPdf($request, $quotePdf['pdf'], $salesforceNotification);
        } catch (Throwable $exception) {
            app(ProjectLegalPdfService::class)->delete($quotePdf['pdf']['path']);

            throw $exception;
        }
    }

    public function prepareQuote(Request $request, Project $project, PdfDownloadUrlService $downloads): JsonResponse
    {
        $this->authorizeProjectAccess($request, $project);
        abort_unless(
            $request->user()->can('pricing.view') && $request->user()->can('output.produce-quote'),
            403,
        );

        $revision = $this->resolveRevision($request, $project);
        $areaIds = $this->resolveSelectedAreaIds($request, $revision);

        abort_unless(
            $revision->validated && $revision->status === ProjectRevisionStatus::Approved,
            403,
            'Quote PDF requires validation passed and quote approved.',
        );

        $tender = $this->resolveQuoteTender($request, $project);
        $includeCover = $this->shouldIncludeQuoteCover($request, $tender);
        $includeLegalPage = $request->boolean('include_legal_page', true);
        $quotePdf = $this->quotePdf(
            request: $request,
            project: $project,
            revision: $revision,
            tender: $tender,
            includeCover: $includeCover,
            includeLegalPage: $includeLegalPage,
            preparedDatasheetsPath: $this->preparedDatasheetsPath($request, $downloads),
            areaIds: $areaIds,
        );

        if ($this->shouldUploadPdfToSalesforce($request, $project)) {
            $pdf = app(ProjectSchedulePdfService::class);

            $this->uploadPdfToSalesforce(
                project: $project,
                revision: $revision,
                filename: $pdf->salesforceQuoteFilename($project, $revision, $includeCover ? $tender : null, $areaIds),
                pdfContent: $quotePdf['content'],
                documentLabel: 'Lighting Quote',
                documentType: $this->quoteSalesforceDocumentType($tender, $includeCover),
                fingerprintHash: app(SalesforcePdfUploadTracker::class)->fingerprint(
                    $project,
                    $revision,
                    'quote',
                    true,
                    $request->boolean('include_datasheets'),
                    $includeCover,
                    $includeCover ? $tender : null,
                    $areaIds,
                    $includeLegalPage,
                ),
            );
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'action_type' => 'quote_pdf.generated',
            'user_email_snapshot' => $request->user()->email,
            'project_name_snapshot' => $project->name,
            'revision_number' => $revision->revision_number,
            'payload' => $this->pdfActivityPayload(
                filename: $quotePdf['pdf']['filename'],
                revision: $revision,
                includeDatasheets: $request->boolean('include_datasheets'),
                areaIds: $areaIds,
                tender: $tender,
                includeCover: $includeCover,
                includeLegalPage: $includeLegalPage,
            ) + [
                'filename' => $quotePdf['pdf']['filename'],
                'tender' => $tender?->account_name,
            ],
        ]);

        $project->markQuoted($revision);

        return response()->json($downloads->register($quotePdf['pdf'], $request->user()->id));
    }

    public function prepareQuoteDatasheets(Request $request, Project $project, PdfDownloadUrlService $downloads): JsonResponse
    {
        $this->authorizeProjectAccess($request, $project);
        abort_unless(
            $request->user()->can('pricing.view') && $request->user()->can('output.produce-quote'),
            403,
        );

        $revision = $this->resolveRevision($request, $project);
        $areaIds = $this->resolveSelectedAreaIds($request, $revision);

        abort_unless(
            $revision->validated && $revision->status === ProjectRevisionStatus::Approved,
            403,
            'Quote PDF requires validation passed and quote approved.',
        );

        $filename = app(ProjectExportFilenameService::class)->make(
            $project,
            $revision,
            ProjectExportFilenameService::ProjectQuote,
            'pdf',
        );

        $datasheetsPdf = app(ProjectDatasheetPdfService::class)->datasheetsPdf(
            project: $project,
            revision: $revision,
            filename: $filename,
            progressToken: $request->string('pdf_progress_token')->toString(),
            progressUserId: $request->user()?->id,
            areaIds: $areaIds,
        );

        return response()->json($downloads->register($datasheetsPdf, $request->user()->id));
    }

    public function zipPreparedQuotes(Request $request, Project $project, PdfDownloadUrlService $downloads): JsonResponse
    {
        $this->authorizeProjectAccess($request, $project);
        abort_unless(
            $request->user()->can('pricing.view') && $request->user()->can('output.produce-quote'),
            403,
        );

        $revision = $this->resolveRevision($request, $project);
        $tokens = collect($request->input('tokens', []))
            ->filter(fn (mixed $token): bool => is_string($token) && preg_match('/^[A-Za-z0-9]{48}$/', $token))
            ->values()
            ->all();

        abort_if($tokens === [], 422, 'No quote PDFs were supplied for the ZIP file.');

        $filename = app(ProjectExportFilenameService::class)->make(
            $project,
            $revision,
            ProjectExportFilenameService::ProjectQuote,
            'zip',
        );

        return response()->json($downloads->registerZip($tokens, $request->user()->id, $filename));
    }

    /**
     * Export the active project revision as a CSV that Excel can open.
     */
    public function csv(Request $request, Project $project): StreamedResponse
    {
        return $this->streamCsv($request, $project, true);
    }

    public function unpricedCsv(Request $request, Project $project): StreamedResponse
    {
        return $this->streamCsv($request, $project, false);
    }

    public function progress(Request $request, string $token): JsonResponse
    {
        abort_if(blank($token) || ! preg_match('/^[A-Za-z0-9_-]{16,80}$/', $token), 404);

        return response()->json(Cache::get($this->progressCacheKey($request, $token), [
            'percent' => 8,
            'message' => 'Starting PDF generation...',
            'complete' => false,
        ]));
    }

    public function download(Request $request, string $token, PdfDownloadUrlService $downloads): BinaryFileResponse
    {
        return $downloads->response($token, $request->user()->id);
    }

    private function streamCsv(Request $request, Project $project, bool $includePrices): StreamedResponse
    {
        $this->authorizeProjectAccess($request, $project);
        abort_unless(
            $includePrices
                ? $request->user()->can('pricing.view') && $request->user()->can('output.produce-priced-schedule')
                : $request->user()->can('output.produce-unpriced-schedule'),
            403,
        );

        $revision = $this->resolveRevision($request, $project);

        abort_if(
            $includePrices && ! $revision->validated,
            403,
            'Priced CSV requires validation passed.',
        );

        $filename = app(ProjectExportFilenameService::class)->make(
            $project,
            $revision,
            ProjectExportFilenameService::LightingSchedule,
            'csv',
        );

        $areas = $revision->areas()
            ->with(['lines' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return response()->streamDownload(function () use ($areas, $includePrices, $project): void {
            $handle = fopen('php://output', 'w');

            $headings = [
                'Area',
                'Ref',
                'Qty',
                'Code',
                'Description',
                'Type',
                'Notes',
                'Status',
            ];

            if ($includePrices) {
                array_splice($headings, 6, 0, ['Unit Price', 'Line Total']);
            }

            fputcsv($handle, $headings);

            foreach ($areas as $area) {
                foreach ($area->lines as $line) {
                    $unitPrice = (float) ($line->totalUnitPriceForProject($project) ?? 0);
                    $quantity = (int) ($line->qty ?? 0);

                    $row = [
                        $area->name,
                        $line->ref,
                        $quantity,
                        $line->code,
                        $line->description,
                        $line->type?->value,
                        $line->notes,
                        $line->status,
                    ];

                    if ($includePrices) {
                        array_splice($row, 6, 0, [
                            number_format($unitPrice, 2, '.', ''),
                            number_format($quantity * $unitPrice, 2, '.', ''),
                        ]);
                    }

                    fputcsv($handle, $row);
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authorizeProjectAccess(Request $request, Project $project): void
    {
        $user = $request->user();

        if ($user->isAdministrator()) {
            return;
        }

        if (! $project->isVisibleTo($user)) {
            abort(403);
        }
    }

    private function resolveRevision(Request $request, Project $project): ProjectRevision
    {
        $revisionId = $request->integer('revision', $project->active_revision_id);

        return ProjectRevision::where('project_id', $project->id)
            ->findOrFail($revisionId);
    }

    /**
     * @return array<int, int>
     */
    private function resolveSelectedAreaIds(Request $request, ProjectRevision $revision): array
    {
        if (! $request->has('area_ids')) {
            return [];
        }

        $areaInput = $request->input('area_ids', []);
        $areaInput = is_string($areaInput) ? explode(',', $areaInput) : $areaInput;

        $requestedAreaIds = collect($areaInput)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        abort_if($requestedAreaIds->isEmpty(), 422, 'Select at least one area for output.');

        $availableAreaIds = $revision->areas()
            ->orderBy('sort_order')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        abort_unless($requestedAreaIds->diff($availableAreaIds)->isEmpty(), 422, 'One or more selected areas are not part of this project revision.');

        if ($requestedAreaIds->count() === $availableAreaIds->count()) {
            return [];
        }

        return $requestedAreaIds->all();
    }

    private function resolveQuoteTender(Request $request, Project $project): ?ProjectTender
    {
        $tenderId = $request->integer('tender_id');

        if ($tenderId <= 0) {
            return null;
        }

        return $project->tenders()->findOrFail($tenderId);
    }

    private function shouldIncludeQuoteCover(Request $request, ?ProjectTender $tender): bool
    {
        return $tender instanceof ProjectTender
            && $request->boolean('include_cover', true);
    }

    private function quoteSalesforceDocumentType(?ProjectTender $tender, bool $includeCover): string
    {
        if (! $includeCover || ! $tender instanceof ProjectTender) {
            return 'quote';
        }

        return 'quote_tender_'.$tender->id;
    }

    /**
     * @return array{pdf: array{path: string, filename: string}, content: string}
     */
    private function quotePdf(
        Request $request,
        Project $project,
        ProjectRevision $revision,
        ?ProjectTender $tender,
        bool $includeCover,
        bool $includeLegalPage,
        ?string $preparedDatasheetsPath = null,
        array $areaIds = [],
    ): array {
        $pdf = app(ProjectSchedulePdfService::class);
        $filename = $pdf->quoteFilename($project, $revision, $includeCover ? $tender : null, $areaIds);
        $quoteContent = $pdf->quoteContent($project, $revision, $tender, $includeCover, $areaIds);
        $legalPdfService = app(ProjectLegalPdfService::class);
        $legalPdf = $includeLegalPage
            ? $legalPdfService->appendLegalPage($quoteContent, $filename)
            : $legalPdfService->storeWithoutLegalPage($quoteContent, $filename);

        try {
            $filename = $legalPdf['filename'];
            $pdfContent = app(ProjectLegalPdfService::class)->content($legalPdf['path']);

            $datasheetPdf = $this->datasheetPdf(
                request: $request,
                project: $project,
                revision: $revision,
                pdfContent: fn (): string => $pdfContent,
                filename: $filename,
                preparedDatasheetsPath: $preparedDatasheetsPath,
                areaIds: $areaIds,
            );

            if ($datasheetPdf === null) {
                return [
                    'pdf' => $legalPdf,
                    'content' => $pdfContent,
                ];
            }

            app(ProjectLegalPdfService::class)->delete($legalPdf['path']);

            return [
                'pdf' => $datasheetPdf,
                'content' => app(ProjectDatasheetPdfService::class)->content($datasheetPdf['path']),
            ];
        } catch (Throwable $exception) {
            app(ProjectLegalPdfService::class)->delete($legalPdf['path']);

            throw $exception;
        }
    }

    private function preparedDatasheetsPath(Request $request, PdfDownloadUrlService $downloads): ?string
    {
        $token = $request->string('datasheet_token')->toString();

        if (blank($token)) {
            return null;
        }

        $metadata = $downloads->metadata($token, $request->user()->id);
        $path = (string) ($metadata['path'] ?? '');

        abort_unless(is_file($path), 404, 'The prepared datasheet PDF is no longer available.');

        return $path;
    }

    /**
     * @return array{title: string, body: string, status: string}|null
     */
    private function uploadPdfToSalesforce(
        Project $project,
        ProjectRevision $revision,
        string $filename,
        string $pdfContent,
        string $documentLabel,
        string $documentType,
        string $fingerprintHash,
    ): ?array {
        $tracker = app(SalesforcePdfUploadTracker::class);

        try {
            $result = app(SalesforceService::class)->uploadPdf(
                project: $project,
                pdfContent: $pdfContent,
                filename: $filename,
            );
        } catch (Throwable $exception) {
            Log::error('Salesforce PDF upload threw an exception', [
                'project_id' => $project->id,
                'revision_id' => $revision->id,
                'filename' => $filename,
                'document_label' => $documentLabel,
                'exception' => $exception,
            ]);

            $result = [
                'success' => false,
                'message' => 'The PDF was generated, but the Salesforce upload failed.',
            ];
        }

        if (! $result['success']) {
            Notification::make()
                ->title($documentLabel.' upload failed')
                ->body($result['message'] ?? 'The PDF could not be uploaded to Salesforce.')
                ->danger()
                ->send();

            return null;
        }

        $salesforceUrl = $result['url'] ?? null;

        $tracker->recordSuccessfulUpload(
            project: $project,
            revision: $revision,
            documentType: $documentType,
            fingerprintHash: $fingerprintHash,
            filename: $filename,
            salesforceResult: $result,
        );

        ActivityLog::create([
            'user_id' => auth()->id(),
            'project_id' => $project->id,
            'action_type' => 'salesforce_pdf.uploaded',
            'user_email_snapshot' => auth()->user()?->email ?? '',
            'project_name_snapshot' => $project->name,
            'revision_number' => $revision->revision_number,
            'payload' => [
                'document_label' => $documentLabel,
                'filename' => $filename,
                'salesforce_pdf_url' => $salesforceUrl,
            ],
        ]);

        return [
            'title' => $documentLabel.' uploaded to Salesforce',
            'body' => $filename.' is now available on the Opportunity.',
            'status' => 'success',
        ];
    }

    /**
     * @return array{path: string, filename: string}|null
     */
    private function datasheetPdf(
        Request $request,
        Project $project,
        ProjectRevision $revision,
        callable $pdfContent,
        string $filename,
        ?string $preparedDatasheetsPath = null,
        array $areaIds = [],
    ): ?array {
        if (! $request->boolean('include_datasheets')) {
            return null;
        }

        if ($preparedDatasheetsPath !== null) {
            return app(ProjectDatasheetPdfService::class)->appendExistingDatasheets(
                documentContent: $pdfContent(),
                filename: $filename,
                datasheetsPath: $preparedDatasheetsPath,
                progressToken: $request->string('pdf_progress_token')->toString(),
                progressUserId: $request->user()?->id,
            );
        }

        return app(ProjectDatasheetPdfService::class)->appendDatasheets(
            project: $project,
            revision: $revision,
            documentContent: $pdfContent(),
            filename: $filename,
            progressToken: $request->string('pdf_progress_token')->toString(),
            progressUserId: $request->user()?->id,
            areaIds: $areaIds,
        );
    }

    /**
     * @return array{path: string, filename: string}
     */
    private function legalPdf(callable $pdfContent, string $filename): array
    {
        return app(ProjectLegalPdfService::class)->appendLegalPage($pdfContent(), $filename);
    }

    /**
     * @param  array{path: string, filename: string}  $pdf
     */
    private function downloadMergedPdf(array $pdf): BinaryFileResponse
    {
        return response()
            ->download($pdf['path'], $pdf['filename'], ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    /**
     * @param  array{path: string, filename: string}  $pdf
     * @param  array{title: string, body: string, status: string}|null  $notification
     */
    private function respondWithPdf(Request $request, array $pdf, ?array $notification = null): Response
    {
        if ($request->boolean('pdf_delivery_link')) {
            $download = app(PdfDownloadUrlService::class)->register($pdf, $request->user()->id);

            if ($notification !== null) {
                $download['notification'] = $notification;
            }

            return response()->json($download);
        }

        return $this->downloadMergedPdf($pdf);
    }

    private function shouldUploadPdfToSalesforce(Request $request, Project $project): bool
    {
        return $request->boolean('salesforce_upload')
            && app(SalesforcePushControl::class)->enabled()
            && ($project->salesforce_project || filled($project->salesforce_id));
    }

    private function shouldUploadSchedulePdfToSalesforce(Request $request, Project $project): bool
    {
        return $request->boolean('salesforce_upload', true)
            && app(SalesforcePushControl::class)->enabled()
            && ($project->salesforce_project || filled($project->salesforce_id));
    }

    /**
     * @return array{filename: string, revision_id: int, revision_number: int, revision_label: string, include_datasheets: bool, area_ids: array<int, int>, area_count: int|null, area_scope: string, tender_id?: int|null, tender_account_name?: string|null, include_cover?: bool, include_legal_page?: bool}
     */
    private function pdfActivityPayload(
        string $filename,
        ProjectRevision $revision,
        bool $includeDatasheets,
        array $areaIds,
        ?ProjectTender $tender = null,
        ?bool $includeCover = null,
        ?bool $includeLegalPage = null,
    ): array {
        $payload = [
            'filename' => $filename,
            'revision_id' => $revision->id,
            'revision_number' => $revision->revision_number,
            'revision_label' => $revision->label(),
            'include_datasheets' => $includeDatasheets,
            'area_ids' => array_values(array_map(fn (int|string $areaId): int => (int) $areaId, $areaIds)),
            'area_count' => $areaIds === [] ? null : count($areaIds),
            'area_scope' => $areaIds === [] ? 'full' : 'selected',
        ];

        if ($tender instanceof ProjectTender || $includeCover !== null) {
            $payload['tender_id'] = $tender?->id;
            $payload['tender_account_name'] = $tender?->account_name;
            $payload['include_cover'] = (bool) $includeCover;
        }

        if ($includeLegalPage !== null) {
            $payload['include_legal_page'] = $includeLegalPage;
        }

        return $payload;
    }

    private function progressCacheKey(Request $request, string $token): string
    {
        return 'pdf-progress:'.$request->user()->id.':'.$token;
    }
}
