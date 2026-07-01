<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use App\Models\SalesforcePdfUpload;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use JsonException;

class SalesforcePdfUploadTracker
{
    /**
     * @throws JsonException
     */
    public function fingerprint(
        Project $project,
        ProjectRevision $revision,
        string $documentType,
        bool $showPrices,
        bool $includeDatasheets = false,
    ): string {
        $areas = ProjectArea::where('project_revision_id', $revision->id)
            ->with(['lines' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $payload = [
            'version' => 2,
            'document_type' => $documentType,
            'show_prices' => $showPrices,
            'include_datasheets' => $includeDatasheets,
            'template_hash' => $this->templateHash(),
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'reference_number' => $project->reference_number,
                'customer_name' => $project->customer_name,
                'site_location' => $project->site_location,
                'date' => $project->date?->toDateString(),
                'quote_notes' => $project->quote_notes,
                'general_notes' => $project->general_notes,
            ],
            'revision' => [
                'id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'validated' => $revision->validated,
                'status' => $revision->status?->value,
            ],
            'areas' => $this->areaPayload($areas, $showPrices),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function isCurrent(Project $project, ProjectRevision $revision, string $documentType, string $fingerprintHash): bool
    {
        return SalesforcePdfUpload::query()
            ->where('project_id', $project->id)
            ->where('project_revision_id', $revision->id)
            ->where('document_type', $documentType)
            ->where('fingerprint_hash', $fingerprintHash)
            ->exists();
    }

    /**
     * @param  array{url?: string|null, contentVersionId?: string|null, contentDocumentId?: string|null}  $salesforceResult
     */
    public function recordSuccessfulUpload(
        Project $project,
        ProjectRevision $revision,
        string $documentType,
        string $fingerprintHash,
        string $filename,
        array $salesforceResult,
    ): SalesforcePdfUpload {
        return SalesforcePdfUpload::updateOrCreate(
            [
                'project_id' => $project->id,
                'project_revision_id' => $revision->id,
                'document_type' => $documentType,
            ],
            [
                'fingerprint_hash' => $fingerprintHash,
                'filename' => $filename,
                'salesforce_content_version_id' => $salesforceResult['contentVersionId'] ?? null,
                'salesforce_content_document_id' => $salesforceResult['contentDocumentId'] ?? null,
                'salesforce_url' => $salesforceResult['url'] ?? null,
                'uploaded_at' => now(),
            ],
        );
    }

    private function templateHash(): ?string
    {
        $path = resource_path('views/pdfs/schedule.blade.php');

        return is_file($path) ? sha1_file($path) ?: null : null;
    }

    /**
     * @param  EloquentCollection<int, ProjectArea>  $areas
     * @return array<int, array<string, mixed>>
     */
    private function areaPayload(EloquentCollection $areas, bool $showPrices): array
    {
        return $areas
            ->map(fn (ProjectArea $area): array => [
                'id' => $area->id,
                'name' => $area->name,
                'sort_order' => $area->sort_order,
                'lines' => $area->lines
                    ->map(fn (ProjectLine $line): array => [
                        'id' => $line->id,
                        'code' => $line->code,
                        'ref' => $line->ref,
                        'description' => $line->description,
                        'qty' => $line->qty,
                        'type' => $line->type?->value,
                        'unit_price' => $showPrices ? $line->unit_price : null,
                        'notes' => $line->notes,
                        'sort_order' => $line->sort_order,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
