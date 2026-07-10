<?php

namespace App\Http\Controllers;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Models\ActivityLog;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Services\DocumentPackPdfService;
use App\Services\PdfDownloadUrlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentPackController extends Controller
{
    public function __invoke(
        Request $request,
        Project $project,
        DocumentPack $documentPack,
        DocumentPackPdfService $pdfService,
        PdfDownloadUrlService $downloads,
    ): Response {
        $this->authorizeProjectAccess($request, $project);
        abort_unless($request->user()->can('output.produce-document-packs'), 403);
        abort_unless($documentPack->project_id === $project->id, 404);

        $revisionId = $request->integer('revision', $project->active_revision_id);
        $revision = ProjectRevision::where('project_id', $project->id)->findOrFail($revisionId);
        $generatedPack = $pdfService->generate($documentPack, $revision, $request->user());
        $containsQuote = $documentPack->items()->where('role', DocumentPackItemRole::Quote->value)->exists();

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
                'contains_quote' => $containsQuote,
            ],
        ]);

        if ($containsQuote) {
            $project->markQuoted($revision);
        }

        if ($request->boolean('pdf_delivery_link')) {
            return response()->json($downloads->register($generatedPack, $request->user()->id));
        }

        return response()
            ->download($generatedPack['path'], $generatedPack['filename'], ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    public function uploadedItem(
        Request $request,
        Project $project,
        DocumentPack $documentPack,
        DocumentPackItem $documentPackItem,
    ): StreamedResponse {
        $this->authorizeProjectAccess($request, $project);
        abort_unless($request->user()->can('output.manage-document-packs'), 403);
        abort_unless($documentPack->project_id === $project->id, 404);
        abort_unless($documentPackItem->document_pack_id === $documentPack->id, 404);
        abort_unless($documentPackItem->source_type === DocumentPackItemSource::Uploaded, 404);
        abort_unless($documentPackItem->file_path !== null, 404);

        $diskName = $documentPackItem->file_disk ?? 'local';
        $filePath = $documentPackItem->file_path;
        $disk = Storage::disk($diskName);

        abort_unless($disk->exists($filePath), 404);

        $filename = str_replace(['\\', '"'], '', $documentPackItem->original_filename ?: 'document-pack-item.pdf');

        return response()->stream(function () use ($disk, $filePath): void {
            $stream = $disk->readStream($filePath);

            if ($stream === false) {
                return;
            }

            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function authorizeProjectAccess(Request $request, Project $project): void
    {
        $user = $request->user();

        if ($user->isAdministrator()) {
            return;
        }

        abort_if(! $project->isVisibleTo($user), 403);
    }
}
