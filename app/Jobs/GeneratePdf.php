<?php

namespace App\Jobs;

use App\Http\Controllers\DocumentPackController;
use App\Http\Controllers\ProjectPdfController;
use App\Models\DocumentPack;
use App\Models\PdfGeneration;
use App\Services\DocumentPackPdfService;
use App\Services\PdfDownloadUrlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

class GeneratePdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = false;

    public function __construct(public int $pdfGenerationId)
    {
        $this->timeout = max(60, (int) config('pdf-generation.job_timeout_seconds', 900));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(
        ProjectPdfController $projectPdfController,
        DocumentPackController $documentPackController,
        DocumentPackPdfService $documentPackPdfService,
        PdfDownloadUrlService $downloads,
    ): void {
        $generation = PdfGeneration::query()
            ->with(['user', 'project'])
            ->find($this->pdfGenerationId);

        if ($generation === null || $generation->status === PdfGeneration::StatusCompleted) {
            return;
        }

        if ($generation->user === null || $generation->project === null) {
            throw new RuntimeException('The user or project for this PDF no longer exists.');
        }

        $generation->update([
            'status' => PdfGeneration::StatusProcessing,
            'progress' => 10,
            'attempt_count' => $this->attempts(),
            'message' => $this->attempts() > 1
                ? "Retrying PDF generation (attempt {$this->attempts()} of {$this->tries})..."
                : 'Generating PDF in the background...',
            'started_at' => $generation->started_at ?? now(),
        ]);

        $parameters = $generation->parameters ?? [];

        if (($parameters['area_ids'] ?? null) === []) {
            unset($parameters['area_ids']);
        }

        $request = Request::create('/', 'GET', $parameters);
        $request->setUserResolver(fn () => $generation->user);
        $request->attributes->set('queued_pdf_worker', true);
        Auth::setUser($generation->user);

        try {
            $response = $this->generate(
                generation: $generation,
                request: $request,
                projectPdfController: $projectPdfController,
                documentPackController: $documentPackController,
                documentPackPdfService: $documentPackPdfService,
                downloads: $downloads,
            );
            $result = $this->responsePayload($response);

            $generation->update([
                'status' => PdfGeneration::StatusCompleted,
                'progress' => 100,
                'message' => 'PDF ready to download.',
                'result' => $result,
                'error_message' => null,
                'completed_at' => now(),
                'expires_at' => now()->addMinutes(max(10, (int) config('pdf-generation.download_retention_minutes', 60))),
            ]);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() < 500) {
                $this->recordFailure($exception);

                return;
            }

            throw $exception;
        } finally {
            Auth::forgetGuards();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->recordFailure($exception);
    }

    private function recordFailure(?Throwable $exception): void
    {
        $generation = PdfGeneration::query()->find($this->pdfGenerationId);

        if ($generation === null || $generation->status === PdfGeneration::StatusCompleted) {
            return;
        }

        $message = $this->safeFailureMessage($exception);

        $generation->update([
            'status' => PdfGeneration::StatusFailed,
            'progress' => 100,
            'attempt_count' => max($generation->attempt_count, $this->attempts()),
            'message' => 'PDF generation failed.',
            'error_message' => $message,
            'completed_at' => now(),
        ]);

        Log::error('Queued PDF generation failed.', [
            'pdf_generation_id' => $generation->id,
            'type' => $generation->type,
            'attempts' => $generation->attempt_count,
            'exception' => $exception,
        ]);
    }

    private function generate(
        PdfGeneration $generation,
        Request $request,
        ProjectPdfController $projectPdfController,
        DocumentPackController $documentPackController,
        DocumentPackPdfService $documentPackPdfService,
        PdfDownloadUrlService $downloads,
    ): Response {
        $request->merge(['pdf_delivery_link' => true]);

        return match ($generation->type) {
            PdfGeneration::TypeSchedule => $projectPdfController->schedule($request, $generation->project),
            PdfGeneration::TypeQuote => $projectPdfController->quote($request, $generation->project),
            PdfGeneration::TypePreparedQuote => $projectPdfController->prepareQuote($request, $generation->project, $downloads),
            PdfGeneration::TypeDatasheets => $projectPdfController->prepareQuoteDatasheets($request, $generation->project, $downloads),
            PdfGeneration::TypeDocumentPack => $documentPackController(
                request: $request,
                project: $generation->project,
                documentPack: DocumentPack::query()->findOrFail((int) ($generation->parameters['document_pack_id'] ?? 0)),
                pdfService: $documentPackPdfService,
                downloads: $downloads,
            ),
            default => throw new RuntimeException('The requested PDF type is not supported.'),
        };
    }

    /** @return array<string, mixed> */
    private function responsePayload(Response $response): array
    {
        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            throw new RuntimeException('The PDF generator returned an unexpected response.');
        }

        $payload = $response->getData(true);

        if (! is_array($payload) || blank($payload['url'] ?? null) || blank($payload['filename'] ?? null)) {
            throw new RuntimeException('The PDF was generated but its download could not be prepared.');
        }

        return $payload;
    }

    private function safeFailureMessage(?Throwable $exception): string
    {
        if ($exception instanceof ProcessTimedOutException) {
            return 'PDF generation took too long. Please retry it; if it fails again, reduce the number of datasheets or documents.';
        }

        if ($exception instanceof TimeoutExceededException) {
            return 'PDF generation exceeded the background processing time limit. Please retry it; if it fails again, reduce the number of datasheets or documents.';
        }

        if ($exception instanceof MaxAttemptsExceededException) {
            return 'The PDF could not be generated after three attempts. Please retry it or contact an administrator if the problem continues.';
        }

        if ($exception instanceof HttpExceptionInterface && filled($exception->getMessage())) {
            return $exception->getMessage();
        }

        if ($exception instanceof RuntimeException && filled($exception->getMessage())) {
            return $exception->getMessage();
        }

        return 'The PDF could not be generated after several attempts. Please try again.';
    }
}
