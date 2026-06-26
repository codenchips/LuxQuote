<?php

namespace App\Services;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Enums\ProjectRevisionStatus;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use App\Models\ProjectRevision;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class DocumentPackPdfService
{
    public function __construct(private ProjectSchedulePdfService $projectPdfService) {}

    /**
     * @return array{path: string, filename: string}
     */
    public function generate(DocumentPack $documentPack, ProjectRevision $revision, User $user): array
    {
        abort_unless($documentPack->project_id === $revision->project_id, 404);

        $items = $documentPack->items()->get();
        abort_if($items->isEmpty(), 422, 'The document pack does not contain any documents.');

        $workingDirectory = storage_path('app/private/document-pack-temp/'.Str::uuid());
        $outputDirectory = storage_path('app/private/document-pack-outputs');
        File::ensureDirectoryExists($workingDirectory);
        File::ensureDirectoryExists($outputDirectory);

        try {
            $inputPaths = [];

            foreach ($items as $index => $item) {
                $inputPaths[] = $this->resolveItemPath(
                    item: $item,
                    revision: $revision,
                    user: $user,
                    workingDirectory: $workingDirectory,
                    index: $index,
                );
            }

            $outputPath = $outputDirectory.'/'.Str::uuid().'.pdf';
            $this->merge($inputPaths, $outputPath);

            return [
                'path' => $outputPath,
                'filename' => $this->filename($documentPack, $revision),
            ];
        } finally {
            File::deleteDirectory($workingDirectory);
        }
    }

    public function assertValidUploadedPdf(string $path): void
    {
        $process = new Process([$this->qpdfBinary(), '--check', $path]);
        $process->setTimeout((float) config('document-packs.process_timeout_seconds', 60));
        $process->run();

        if (! in_array($process->getExitCode(), [0, 3], true)) {
            throw new RuntimeException('The PDF is corrupt, encrypted, or uses an unsupported structure.');
        }
    }

    /** @param array<int, string> $inputPaths */
    private function merge(array $inputPaths, string $outputPath): void
    {
        $arguments = [$this->qpdfBinary(), '--empty', '--pages'];

        foreach ($inputPaths as $inputPath) {
            $arguments[] = $inputPath;
            $arguments[] = '1-z';
        }

        $arguments[] = '--';
        $arguments[] = $outputPath;

        $process = new Process($arguments);
        $process->setTimeout((float) config('document-packs.process_timeout_seconds', 60));
        $process->run();

        if (! $process->isSuccessful() || ! File::isFile($outputPath)) {
            File::delete($outputPath);

            throw new RuntimeException('The document pack could not be merged. Please check the uploaded PDFs and try again.');
        }
    }

    private function resolveItemPath(
        DocumentPackItem $item,
        ProjectRevision $revision,
        User $user,
        string $workingDirectory,
        int $index,
    ): string {
        $role = $item->role;
        abort_unless($role instanceof DocumentPackItemRole, 422, 'The document pack contains an unsupported document role.');
        abort_unless($item->source_type === $role->source(), 422, 'The document pack contains an invalid document source.');

        if ($item->source_type === DocumentPackItemSource::Uploaded) {
            abort_if($item->file_path === null, 422, "The {$role->label()} PDF is missing.");

            $disk = Storage::disk($item->file_disk ?? 'local');
            abort_unless($disk->exists($item->file_path), 422, "The {$role->label()} PDF could not be found.");

            return $disk->path($item->file_path);
        }

        $outputPath = $workingDirectory.'/'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.pdf';

        $content = match ($role) {
            DocumentPackItemRole::Quote => $this->quoteContent($revision, $user),
            DocumentPackItemRole::UnpricedSchedule => $this->scheduleContent($revision, $user),
            default => throw new RuntimeException('The generated document role is not supported.'),
        };

        File::put($outputPath, $content);

        return $outputPath;
    }

    private function quoteContent(ProjectRevision $revision, User $user): string
    {
        abort_unless($user->can('pricing.view') && $user->can('output.produce-quote'), 403);
        abort_unless(
            $revision->validated && $revision->status === ProjectRevisionStatus::Approved,
            403,
            'Quote PDF requires validation passed and quote approved.',
        );

        return $this->projectPdfService->quoteContent($revision->project, $revision);
    }

    private function scheduleContent(ProjectRevision $revision, User $user): string
    {
        abort_unless($user->can('output.produce-unpriced-schedule'), 403);

        return $this->projectPdfService->content($revision->project, $revision);
    }

    private function filename(DocumentPack $documentPack, ProjectRevision $revision): string
    {
        $reference = $documentPack->project->reference_number ?? 'project-'.$documentPack->project_id;

        return collect([$reference, $documentPack->name, $revision->label(), 'document-pack'])
            ->map(fn (string $part): string => trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $part), '-'))
            ->filter()
            ->implode('-').'.pdf';
    }

    private function qpdfBinary(): string
    {
        return (string) config('document-packs.qpdf_binary', 'qpdf');
    }
}
