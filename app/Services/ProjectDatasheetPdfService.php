<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProjectDatasheetPdfService
{
    /**
     * @return array{path: string, filename: string}
     */
    public function appendDatasheets(
        Project $project,
        ProjectRevision $revision,
        string $documentContent,
        string $filename,
    ): array {
        $workingDirectory = storage_path('app/private/datasheet-merge-temp/'.Str::uuid());
        $outputDirectory = storage_path('app/private/datasheet-merge-outputs');
        File::ensureDirectoryExists($workingDirectory);
        File::ensureDirectoryExists($outputDirectory);

        $documentPath = $workingDirectory.'/document.pdf';
        $datasheetsPath = $workingDirectory.'/datasheets.pdf';
        $mergedPath = $outputDirectory.'/'.Str::uuid().'.pdf';

        try {
            File::put($documentPath, $documentContent);

            $projectSlug = $this->projectSlug($project);
            $datasheetFilename = $this->requestDatasheetGeneration($project, $revision, $projectSlug);
            $this->downloadDatasheets($datasheetFilename, $datasheetsPath);
            $this->assertValidPdf($documentPath, 'The generated document PDF could not be merged.');
            $this->assertValidPdf($datasheetsPath, 'The datasheets PDF could not be merged.');
            $this->merge([$documentPath, $datasheetsPath], $mergedPath);

            return [
                'path' => $mergedPath,
                'filename' => $this->withDatasheetsFilename($filename),
            ];
        } finally {
            File::deleteDirectory($workingDirectory);
        }
    }

    public function content(string $path): string
    {
        return File::get($path);
    }

    private function requestDatasheetGeneration(Project $project, ProjectRevision $revision, string $projectSlug): string
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) config('services.datasheets.timeout', 60))
            ->post((string) config('services.datasheets.endpoint'), $this->formPayload($project, $revision, $projectSlug));

        if (! $response->successful()) {
            throw new RuntimeException('The datasheet PDF could not be generated.');
        }

        $result = $this->generationResult($response->body());

        if (! $result['complete']) {
            throw new RuntimeException('The datasheet PDF did not finish generating.');
        }

        return $result['filename'] ?? $projectSlug.'.pdf';
    }

    private function downloadDatasheets(string $filename, string $destinationPath): void
    {
        $url = rtrim((string) config('services.datasheets.public_base_url'), '/').'/'.ltrim($filename, '/');

        $response = Http::timeout((int) config('services.datasheets.timeout', 60))->get($url);

        if (! $response->successful() || blank($response->body())) {
            throw new RuntimeException('The datasheet PDF could not be downloaded.');
        }

        File::put($destinationPath, $response->body());
    }

    /**
     * @return array{complete: bool, filename: string|null}
     */
    private function generationResult(string $body): array
    {
        preg_match_all('/\{[^{}]*\}/', $body, $matches);

        foreach (array_reverse($matches[0]) as $json) {
            $payload = json_decode($json, true);

            if (! is_array($payload) || ($payload['complete'] ?? false) !== true) {
                continue;
            }

            $filename = $payload['filename'] ?? null;

            return [
                'complete' => true,
                'filename' => filled($filename) ? basename((string) $filename) : null,
            ];
        }

        return [
            'complete' => false,
            'filename' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(Project $project, ProjectRevision $revision, string $projectSlug): array
    {
        return [
            ...$this->payload($project, $revision, $projectSlug),
            'skus' => json_encode($this->skuPayload($revision), JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Project $project, ProjectRevision $revision, string $projectSlug): array
    {
        return [
            'project_slug' => $projectSlug,
            'project_version' => $revision->revision_number,
            'info_project_name' => $project->name,
            'info_project_id' => $project->reference_number,
            'info_engineer' => 'n/a',
            'info_design_engineer' => 'n/a',
            'info_date' => ($project->last_edited_at ?? $revision->updated_at ?? $project->updated_at)?->format('d/m/Y'),
            'include_schedule' => false,
            'include_datasheets' => true,
            'include_floorplans' => false,
            'include_cover' => false,
            'schedule_type' => 'by_project',
        ];
    }

    /**
     * @return array<int, array{qty: int, sku: string, ref: string, note: string|null, brand: string}>
     */
    private function skuPayload(ProjectRevision $revision): array
    {
        return $revision->areas()
            ->with(['lines' => fn ($query) => $query->orderBy('sort_order')->with('product')])
            ->get()
            ->flatMap->lines
            ->filter(fn (ProjectLine $line): bool => filled($line->code))
            ->map(fn (ProjectLine $line): array => [
                'qty' => (int) ($line->qty ?? 0),
                'sku' => (string) $line->code,
                'ref' => (string) ($line->ref ?? ''),
                'note' => $line->notes,
                'brand' => $this->brand($line),
            ])
            ->values()
            ->all();
    }

    private function brand(ProjectLine $line): string
    {
        $site = strtolower((string) $line->product?->site);

        return match ($site) {
            'xcite' => 'Xcite',
            'tamlite' => 'Tamlite',
            'luxena' => 'Luxena',
            default => 'Custom',
        };
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

        if (! in_array($process->getExitCode(), [0, 3], true) || ! File::isFile($outputPath)) {
            File::delete($outputPath);

            throw new RuntimeException('The PDF could not be merged with datasheets.');
        }
    }

    private function assertValidPdf(string $path, string $message): void
    {
        $process = new Process([$this->qpdfBinary(), '--check', $path]);
        $process->setTimeout((float) config('document-packs.process_timeout_seconds', 60));
        $process->run();

        if (! in_array($process->getExitCode(), [0, 3], true)) {
            throw new RuntimeException($message);
        }
    }

    private function withDatasheetsFilename(string $filename): string
    {
        return Str::of($filename)
            ->replaceEnd('.pdf', '')
            ->append('-with-datasheets.pdf')
            ->toString();
    }

    private function projectSlug(Project $project): string
    {
        return Str::slug($project->name ?: 'project-'.$project->id);
    }

    private function qpdfBinary(): string
    {
        return (string) config('document-packs.qpdf_binary', 'qpdf');
    }
}
