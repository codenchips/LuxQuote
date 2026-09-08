<?php

namespace App\Services;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Enums\ProjectRevisionStatus;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use App\Models\ProjectRevision;
use App\Models\ProjectTender;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class DocumentPackPdfService
{
    private ProjectLegalPdfService $projectLegalPdfService;

    private DocumentPackGeneratedOptionsService $generatedOptions;

    private ProjectDatasheetPdfService $datasheetPdfService;

    public function __construct(
        private ProjectSchedulePdfService $projectPdfService,
        ?ProjectLegalPdfService $projectLegalPdfService = null,
        ?DocumentPackGeneratedOptionsService $generatedOptions = null,
        ?ProjectDatasheetPdfService $datasheetPdfService = null,
    ) {
        $this->projectLegalPdfService = $projectLegalPdfService ?? app(ProjectLegalPdfService::class);
        $this->generatedOptions = $generatedOptions ?? app(DocumentPackGeneratedOptionsService::class);
        $this->datasheetPdfService = $datasheetPdfService ?? app(ProjectDatasheetPdfService::class);
    }

    /**
     * @return array{path: string, filename: string}
     */
    public function generate(
        DocumentPack $documentPack,
        ProjectRevision $revision,
        User $user,
        ?ProjectTender $tender = null,
        bool $includeQuoteCover = false,
    ): array {
        abort_unless($documentPack->project_id === $revision->project_id, 404);
        abort_if($tender !== null && $tender->project_id !== $documentPack->project_id, 404);

        $items = $documentPack->items()->get();
        abort_if($items->isEmpty(), 422, 'The document pack does not contain any documents.');

        $workingDirectory = storage_path('app/private/document-pack-temp/'.Str::uuid());
        $outputDirectory = storage_path('app/private/document-pack-outputs');
        File::ensureDirectoryExists($workingDirectory);
        File::ensureDirectoryExists($outputDirectory);

        try {
            $inputPaths = [];
            $datasheetPaths = [];

            foreach ($items as $index => $item) {
                $inputPaths[] = $this->resolveItemPath(
                    item: $item,
                    revision: $revision,
                    user: $user,
                    workingDirectory: $workingDirectory,
                    index: $index,
                    datasheetPaths: $datasheetPaths,
                    tender: $tender,
                    includeQuoteCover: $includeQuoteCover,
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
        array &$datasheetPaths,
        ?ProjectTender $tender,
        bool $includeQuoteCover,
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

        $options = $role->source() === DocumentPackItemSource::Generated
            ? $this->generatedOptions->resolve($item->configuration, $revision)
            : null;

        abort_if($options !== null && ! $options['valid'], 422, $options['message'] ?? 'The generated document options are no longer valid.');
        $areaIds = $options['area_ids'] ?? [];

        $content = match ($role) {
            DocumentPackItemRole::Quote => $this->quoteContent($revision, $user, $areaIds, $tender, $includeQuoteCover),
            DocumentPackItemRole::UnpricedSchedule => $this->scheduleContent($revision, $user, $areaIds),
            DocumentPackItemRole::StandardLegalPage => File::get($this->projectLegalPdfService->legalPagePath()),
            default => throw new RuntimeException('The generated document role is not supported.'),
        };

        if (($options['include_datasheets'] ?? false) === true) {
            $datasheetsPath = $this->datasheetsPath($revision, $areaIds, $workingDirectory, $datasheetPaths);
            $mergedPdf = $this->datasheetPdfService->appendExistingDatasheets(
                documentContent: $content,
                filename: 'document-pack-item.pdf',
                datasheetsPath: $datasheetsPath,
            );

            try {
                File::copy($mergedPdf['path'], $outputPath);
            } finally {
                File::delete($mergedPdf['path']);
            }

            return $outputPath;
        }

        File::put($outputPath, $content);

        return $outputPath;
    }

    /** @param array<int, int> $areaIds */
    private function quoteContent(
        ProjectRevision $revision,
        User $user,
        array $areaIds,
        ?ProjectTender $tender,
        bool $includeCover,
    ): string {
        abort_unless($user->can('pricing.view') && $user->can('output.produce-quote'), 403);
        abort_unless(
            $revision->validated && $revision->status === ProjectRevisionStatus::Approved,
            403,
            'Quote PDF requires validation passed and quote approved.',
        );

        return $this->projectPdfService->quoteContent(
            $revision->project,
            $revision,
            tender: $tender,
            includeCover: $includeCover,
            areaIds: $areaIds,
        );
    }

    /** @param array<int, int> $areaIds */
    private function scheduleContent(ProjectRevision $revision, User $user, array $areaIds): string
    {
        abort_unless($user->can('output.produce-unpriced-schedule'), 403);

        return $this->projectPdfService->content($revision->project, $revision, $areaIds);
    }

    /**
     * @param  array<int, int>  $areaIds
     * @param  array<string, string>  $datasheetPaths
     */
    private function datasheetsPath(
        ProjectRevision $revision,
        array $areaIds,
        string $workingDirectory,
        array &$datasheetPaths,
    ): string {
        $key = $areaIds === [] ? 'all' : implode('-', $areaIds);

        if (isset($datasheetPaths[$key])) {
            return $datasheetPaths[$key];
        }

        $generatedPdf = $this->datasheetPdfService->datasheetsPdf(
            project: $revision->project,
            revision: $revision,
            filename: 'datasheets.pdf',
            areaIds: $areaIds,
        );
        $path = $workingDirectory.'/datasheets-'.hash('sha256', $key).'.pdf';

        try {
            File::copy($generatedPdf['path'], $path);
        } finally {
            File::delete($generatedPdf['path']);
        }

        $datasheetPaths[$key] = $path;

        return $path;
    }

    private function filename(DocumentPack $documentPack, ProjectRevision $revision): string
    {
        return app(ProjectExportFilenameService::class)->make(
            $documentPack->project,
            $revision,
            ProjectExportFilenameService::DocumentPack,
            'pdf',
        );
    }

    private function qpdfBinary(): string
    {
        return (string) config('document-packs.qpdf_binary', 'qpdf');
    }
}
