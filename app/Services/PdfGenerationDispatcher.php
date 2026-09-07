<?php

namespace App\Services;

use App\Jobs\GeneratePdf;
use App\Models\PdfGeneration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class PdfGenerationDispatcher
{
    /** @param array<string, mixed> $parameters */
    public function dispatch(User $user, Project $project, string $type, array $parameters): PdfGeneration
    {
        if (! in_array($type, $this->supportedTypes(), true)) {
            throw new InvalidArgumentException('Unsupported PDF generation type.');
        }

        $generation = PdfGeneration::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'project_id' => $project->id,
            'type' => $type,
            'status' => PdfGeneration::StatusQueued,
            'progress' => 0,
            'attempt_count' => 0,
            'message' => 'Waiting for a PDF worker...',
            'parameters' => $parameters,
        ]);

        return $this->redispatch($generation);
    }

    public function redispatch(PdfGeneration $generation): PdfGeneration
    {
        $generation->update([
            'status' => PdfGeneration::StatusQueued,
            'progress' => 0,
            'attempt_count' => 0,
            'message' => 'Waiting for a PDF worker...',
            'result' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
            'expires_at' => null,
        ]);

        try {
            GeneratePdf::dispatch($generation->id)
                ->onQueue((string) config('pdf-generation.queue', 'pdf'))
                ->afterCommit();
        } catch (Throwable $exception) {
            Log::error('PDF generation could not be queued.', [
                'pdf_generation_id' => $generation->id,
                'exception' => $exception,
            ]);

            $generation->update([
                'status' => PdfGeneration::StatusFailed,
                'message' => 'PDF generation could not be queued.',
                'error_message' => 'The PDF worker was unavailable. Please try again.',
                'completed_at' => now(),
            ]);
        }

        return $generation->refresh();
    }

    /** @return array<string, mixed> */
    public function response(PdfGeneration $generation): array
    {
        $generation->refresh();

        return [
            'id' => $generation->uuid,
            'status' => $generation->status,
            'progress' => $generation->progress,
            'message' => $generation->message,
            'status_url' => route('pdf-generations.show', $generation),
            'retry_url' => $generation->status === PdfGeneration::StatusFailed
                ? route('pdf-generations.retry', $generation)
                : null,
            'result' => $generation->status === PdfGeneration::StatusCompleted ? $generation->result : null,
        ];
    }

    /** @return array<int, string> */
    private function supportedTypes(): array
    {
        return [
            PdfGeneration::TypeSchedule,
            PdfGeneration::TypeQuote,
            PdfGeneration::TypePreparedQuote,
            PdfGeneration::TypeDatasheets,
            PdfGeneration::TypeDocumentPack,
        ];
    }
}
