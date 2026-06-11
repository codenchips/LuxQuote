<?php

namespace App\Http\Controllers;

use App\Enums\ProjectVisibility;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Services\ProjectSchedulePdfService;
use Illuminate\Http\Request;
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
}
