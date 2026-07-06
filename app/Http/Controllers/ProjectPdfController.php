<?php

namespace App\Http\Controllers;

use App\Enums\ProjectRevisionStatus;
use App\Enums\ProjectVisibility;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Services\ProjectDatasheetPdfService;
use App\Services\ProjectLegalPdfService;
use App\Services\ProjectSchedulePdfService;
use App\Services\SalesforcePdfUploadTracker;
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

        $pdf = app(ProjectSchedulePdfService::class);
        $filename = $pdf->filename($project, $revision);
        $builder = $pdf->builder($project, $revision);
        $legalPdf = $this->legalPdf(
            pdfContent: fn (): string => $pdf->contentFromBuilder($builder),
            filename: $filename,
        );

        try {
            $filename = $legalPdf['filename'];
            $pdfContent = app(ProjectLegalPdfService::class)->content($legalPdf['path']);

            $datasheetPdf = $this->datasheetPdf(
                request: $request,
                project: $project,
                revision: $revision,
                pdfContent: fn (): string => $pdfContent,
                filename: $filename,
            );

            if ($datasheetPdf !== null) {
                app(ProjectLegalPdfService::class)->delete($legalPdf['path']);
                $filename = $datasheetPdf['filename'];
                $pdfContent = app(ProjectDatasheetPdfService::class)->content($datasheetPdf['path']);
            }

            if ($this->shouldUploadPdfToSalesforce($request, $project)) {
                $this->uploadPdfToSalesforce(
                    project: $project,
                    revision: $revision,
                    filename: $filename,
                    pdfContent: $pdfContent,
                    documentLabel: 'Lighting Schedule',
                    documentType: 'schedule',
                    fingerprintHash: app(SalesforcePdfUploadTracker::class)->fingerprint(
                        $project,
                        $revision,
                        'schedule',
                        false,
                        $request->boolean('include_datasheets'),
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
                'payload' => [
                    'filename' => $filename,
                ],
            ]);

            if ($datasheetPdf !== null) {
                return $this->downloadMergedPdf($datasheetPdf);
            }

            return $this->downloadMergedPdf($legalPdf);
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

        abort_unless(
            $revision->validated && $revision->status === ProjectRevisionStatus::Approved,
            403,
            'Quote PDF requires validation passed and quote approved.',
        );

        $pdf = app(ProjectSchedulePdfService::class);
        $filename = $pdf->quoteFilename($project, $revision);
        $builder = $pdf->quoteBuilder($project, $revision);
        $legalPdf = $this->legalPdf(
            pdfContent: fn (): string => $pdf->contentFromBuilder($builder),
            filename: $filename,
        );

        try {
            $filename = $legalPdf['filename'];
            $pdfContent = app(ProjectLegalPdfService::class)->content($legalPdf['path']);

            $datasheetPdf = $this->datasheetPdf(
                request: $request,
                project: $project,
                revision: $revision,
                pdfContent: fn (): string => $pdfContent,
                filename: $filename,
            );

            if ($datasheetPdf !== null) {
                app(ProjectLegalPdfService::class)->delete($legalPdf['path']);
                $filename = $datasheetPdf['filename'];
                $pdfContent = app(ProjectDatasheetPdfService::class)->content($datasheetPdf['path']);
            }

            if ($this->shouldUploadPdfToSalesforce($request, $project)) {
                $this->uploadPdfToSalesforce(
                    project: $project,
                    revision: $revision,
                    filename: $filename,
                    pdfContent: $pdfContent,
                    documentLabel: 'Lighting Quote',
                    documentType: 'quote',
                    fingerprintHash: app(SalesforcePdfUploadTracker::class)->fingerprint(
                        $project,
                        $revision,
                        'quote',
                        true,
                        $request->boolean('include_datasheets'),
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
                'payload' => [
                    'filename' => $filename,
                ],
            ]);

            $project->markQuoted($revision);

            if ($datasheetPdf !== null) {
                return $this->downloadMergedPdf($datasheetPdf);
            }

            return $this->downloadMergedPdf($legalPdf);
        } catch (Throwable $exception) {
            app(ProjectLegalPdfService::class)->delete($legalPdf['path']);

            throw $exception;
        }
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

        $filename = collect([
            $includePrices ? 'priced-schedule' : 'unpriced-schedule',
            $project->reference_number ?? 'proj-'.$project->id,
            $revision->label(),
        ])->implode('-').'.csv';

        $areas = $revision->areas()
            ->with(['lines' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return response()->streamDownload(function () use ($areas, $includePrices): void {
            $handle = fopen('php://output', 'w');

            $headings = [
                'Area',
                'Code',
                'Ref',
                'Description',
                'Qty',
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
                    $unitPrice = (float) ($line->unit_price ?? 0);
                    $quantity = (int) ($line->qty ?? 0);

                    $row = [
                        $area->name,
                        $line->code,
                        $line->ref,
                        $line->description,
                        $quantity,
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

        if (
            $project->visibility !== ProjectVisibility::Open
            && $project->user_id !== $user->id
        ) {
            abort(403);
        }
    }

    private function resolveRevision(Request $request, Project $project): ProjectRevision
    {
        $revisionId = $request->integer('revision', $project->active_revision_id);

        return ProjectRevision::where('project_id', $project->id)
            ->findOrFail($revisionId);
    }

    private function uploadPdfToSalesforce(
        Project $project,
        ProjectRevision $revision,
        string $filename,
        string $pdfContent,
        string $documentLabel,
        string $documentType,
        string $fingerprintHash,
    ): void {
        $tracker = app(SalesforcePdfUploadTracker::class);

        if ($tracker->isCurrent($project, $revision, $documentType, $fingerprintHash)) {
            return;
        }

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

            return;
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
    ): ?array {
        if (! $request->boolean('include_datasheets')) {
            return null;
        }

        return app(ProjectDatasheetPdfService::class)->appendDatasheets(
            project: $project,
            revision: $revision,
            documentContent: $pdfContent(),
            filename: $filename,
            progressToken: $request->string('pdf_progress_token')->toString(),
            progressUserId: $request->user()?->id,
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

    private function shouldUploadPdfToSalesforce(Request $request, Project $project): bool
    {
        return $request->boolean('salesforce_upload')
            && ($project->salesforce_project || filled($project->salesforce_id));
    }

    private function progressCacheKey(Request $request, string $token): string
    {
        return 'pdf-progress:'.$request->user()->id.':'.$token;
    }
}
