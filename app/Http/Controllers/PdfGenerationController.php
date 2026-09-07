<?php

namespace App\Http\Controllers;

use App\Models\PdfGeneration;
use App\Services\PdfGenerationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdfGenerationController extends Controller
{
    public function show(
        Request $request,
        PdfGeneration $pdfGeneration,
        PdfGenerationDispatcher $dispatcher,
    ): JsonResponse {
        $this->authorizeAccess($request, $pdfGeneration);

        if ($pdfGeneration->status === PdfGeneration::StatusCompleted
            && $pdfGeneration->expires_at?->isPast()) {
            $pdfGeneration->update([
                'status' => PdfGeneration::StatusFailed,
                'message' => 'The prepared download has expired.',
                'error_message' => 'The prepared download expired. Retry to generate a fresh copy.',
                'result' => null,
            ]);
        }

        $payload = $dispatcher->response($pdfGeneration);
        $payload['error'] = $pdfGeneration->error_message;

        return response()->json($payload);
    }

    public function retry(
        Request $request,
        PdfGeneration $pdfGeneration,
        PdfGenerationDispatcher $dispatcher,
    ): JsonResponse {
        $this->authorizeAccess($request, $pdfGeneration);
        abort_unless($pdfGeneration->status === PdfGeneration::StatusFailed, 409, 'Only failed PDF generations can be retried.');
        $this->authorizeCapability($request, $pdfGeneration);

        $generation = $dispatcher->redispatch($pdfGeneration);

        return response()->json($dispatcher->response($generation), 202);
    }

    private function authorizeAccess(Request $request, PdfGeneration $generation): void
    {
        abort_unless($generation->user_id === $request->user()->id, 403);
        abort_unless(
            $request->user()->isAdministrator() || $generation->project->isVisibleTo($request->user()),
            403,
        );
    }

    private function authorizeCapability(Request $request, PdfGeneration $generation): void
    {
        $permitted = match ($generation->type) {
            PdfGeneration::TypeSchedule => $request->user()->can('output.produce-unpriced-schedule'),
            PdfGeneration::TypeQuote, PdfGeneration::TypePreparedQuote, PdfGeneration::TypeDatasheets => $request->user()->can('pricing.view')
                && $request->user()->can('output.produce-quote'),
            PdfGeneration::TypeDocumentPack => $request->user()->can('output.produce-document-packs'),
            default => false,
        };

        abort_unless($permitted, 403);
    }
}
