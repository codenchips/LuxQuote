<?php

namespace App\Http\Controllers;

use App\Enums\ProjectVisibility;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectRevision;
use Illuminate\Http\Request;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\Response;

class ProjectPdfController extends Controller
{
    /**
     * Generate and download the lighting schedule PDF for a project revision.
     */
    public function schedule(Request $request, Project $project): Response
    {
        $user = $request->user();

        // Non-admins may only access open projects or their own
        if ($user->role !== UserRole::Admin) {
            if (
                $project->visibility !== ProjectVisibility::Open
                && $project->user_id !== $user->id
            ) {
                abort(403);
            }
        }

        // Resolve the requested revision, defaulting to the active one
        $revisionId = $request->integer('revision', $project->active_revision_id);

        $revision = ProjectRevision::where('project_id', $project->id)
            ->findOrFail($revisionId);

        $areas = ProjectArea::where('project_revision_id', $revision->id)
            ->with([
                'lines' => fn ($q) => $q->orderBy('sort_order')->with('product'),
            ])
            ->orderBy('sort_order')
            ->get();

        $filename = collect([
            'schedule',
            $project->reference_number ?? 'proj-'.$project->id,
            'R'.$revision->revision_number,
        ])->implode('-').'.pdf';

        $generatedAt = now()->format('d/m/Y g:ia');

        // Puppeteer native footer: <span class="pageNumber"> and <span class="totalPages">
        // are automatically substituted with real values during PDF rendering.
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
                // Required inside Docker / Sail — disables the Chrome sandbox
                $browsershot->noSandbox();
                // Native Puppeteer footer — the only reliable way to get real page numbers.
                // showBrowserHeaderAndFooter() enables both; suppress the default header with
                // a blank template so only our custom footer appears.
                $browsershot->showBrowserHeaderAndFooter();
                $browsershot->headerHtml('<p>Header</p>');
                $browsershot->footerHtml($footerHtml);
            })
            ->format('A4')
            ->inline($filename)
            ->toResponse($request);
    }
}
