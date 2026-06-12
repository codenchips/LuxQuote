<?php

namespace App\Http\Controllers;

use App\Enums\ProjectRevisionStatus;
use App\Enums\ProjectVisibility;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Services\ProjectSchedulePdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        return $pdf->builder($project, $revision)
            ->inline($filename)
            ->toResponse($request);
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

        return $pdf->quoteBuilder($project, $revision)
            ->inline($filename)
            ->toResponse($request);
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
            'R'.$revision->revision_number,
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
}
