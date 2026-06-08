<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectRevision;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

class ProjectSchedulePdfService
{
    public function filename(Project $project, ProjectRevision $revision): string
    {
        return collect([
            'schedule',
            $project->reference_number ?? 'proj-'.$project->id,
            'R'.$revision->revision_number,
        ])->implode('-').'.pdf';
    }

    public function content(Project $project, ProjectRevision $revision): string
    {
        return base64_decode($this->builder($project, $revision)->base64(), true) ?: '';
    }

    public function builder(Project $project, ProjectRevision $revision): PdfBuilder
    {
        $areas = ProjectArea::where('project_revision_id', $revision->id)
            ->with([
                'lines' => fn ($query) => $query->orderBy('sort_order')->with('product'),
            ])
            ->orderBy('sort_order')
            ->get();

        $generatedAt = now()->format('d/m/Y g:ia');

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
        ])
            ->withBrowsershot(function ($browsershot) use ($footerHtml): void {
                $browsershot->noSandbox();
                $browsershot->showBrowserHeaderAndFooter();
                $browsershot->headerHtml('<p>Header</p>');
                $browsershot->footerHtml($footerHtml);
            })
            ->format('A4');
    }
}
