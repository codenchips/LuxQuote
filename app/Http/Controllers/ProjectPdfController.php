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

        return Pdf::view('pdfs.schedule', [
            'project' => $project->load('user'),
            'revision' => $revision,
            'areas' => $areas,
        ])
            ->withBrowsershot(function ($browsershot): void {
                // Required inside Docker / Sail — disables the Chrome sandbox
                $browsershot->noSandbox();
            })
            ->format('A4')
            ->download($filename)
            ->toResponse($request);
    }
}
