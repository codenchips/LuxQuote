<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectRevision;
use InvalidArgumentException;

class ProjectExportFilenameService
{
    public const LightingSchedule = 'LS';

    public const ProjectQuote = 'PQ';

    public const DocumentPack = 'DP';

    public function make(
        Project $project,
        ProjectRevision $revision,
        string $documentTypeCode,
        string $extension,
    ): string {
        $documentTypeCode = strtoupper($documentTypeCode);
        $extension = strtolower(ltrim($extension, '.'));

        if (! preg_match('/^[A-Z]{2}$/', $documentTypeCode)) {
            throw new InvalidArgumentException('The document type code must contain exactly two letters.');
        }

        if (! preg_match('/^[a-z0-9]+$/', $extension)) {
            throw new InvalidArgumentException('The filename extension is invalid.');
        }

        return collect([
            $project->reference_number ?? 'Project-'.$project->id,
            $project->name,
            'TL',
            $documentTypeCode,
            $revision->label(),
        ])
            ->map(fn (?string $part): string => $this->filenamePart($part ?: 'Project'))
            ->implode('-').'.'.$extension;
    }

    private function filenamePart(string $part): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $part), '-');
    }
}
