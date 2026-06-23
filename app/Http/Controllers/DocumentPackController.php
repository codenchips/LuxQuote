<?php

namespace App\Http\Controllers;

use App\Enums\ProjectVisibility;
use App\Models\ActivityLog;
use App\Models\DocumentPack;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Services\DocumentPackPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentPackController extends Controller
{
    public function __invoke(
        Request $request,
        Project $project,
        DocumentPack $documentPack,
        DocumentPackPdfService $pdfService,
    ): BinaryFileResponse {
        $this->authorizeProjectAccess($request, $project);
        abort_unless($request->user()->can('output.produce-document-packs'), 403);
        abort_unless($documentPack->project_id === $project->id, 404);

        $revisionId = $request->integer('revision', $project->active_revision_id);
        $revision = ProjectRevision::where('project_id', $project->id)->findOrFail($revisionId);
        $generatedPack = $pdfService->generate($documentPack, $revision, $request->user());

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'action_type' => 'document_pack.generated',
            'user_email_snapshot' => $request->user()->email,
            'project_name_snapshot' => $project->name,
            'revision_number' => $revision->revision_number,
            'payload' => [
                'document_pack_id' => $documentPack->id,
                'document_pack_name' => $documentPack->name,
                'filename' => $generatedPack['filename'],
            ],
        ]);

        return response()
            ->download($generatedPack['path'], $generatedPack['filename'], ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    private function authorizeProjectAccess(Request $request, Project $project): void
    {
        $user = $request->user();

        if ($user->isAdministrator()) {
            return;
        }

        abort_if(
            $project->visibility !== ProjectVisibility::Open && $project->user_id !== $user->id,
            403,
        );
    }
}
