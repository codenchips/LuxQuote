<?php

namespace App\Services;

use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentPackGeneratedOptionsService
{
    /**
     * @param  array<int, int|string>  $selectedAreaIds
     * @return array{version: int, area_scope: string, area_names: array<int, string>, include_datasheets: bool}
     */
    public function configuration(
        ProjectRevision $revision,
        array $selectedAreaIds,
        bool $includeDatasheets,
    ): array {
        $areas = $revision->areas()
            ->orderBy('sort_order')
            ->get(['id', 'name']);
        $areaIds = collect($selectedAreaIds)
            ->map(fn (mixed $areaId): int => (int) $areaId)
            ->filter(fn (int $areaId): bool => $areaId > 0)
            ->unique()
            ->values();

        if ($areaIds->isEmpty()) {
            throw ValidationException::withMessages([
                'documentPackOptionAreaIds' => 'Select at least one area.',
            ]);
        }

        if ($areaIds->diff($areas->pluck('id')->map(fn (mixed $areaId): int => (int) $areaId))->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documentPackOptionAreaIds' => 'One or more selected areas are not part of this project revision.',
            ]);
        }

        $allAreasSelected = $areaIds->count() === $areas->count();

        if (! $allAreasSelected) {
            $duplicateSelectedNames = $areas
                ->filter(fn (ProjectArea $area): bool => $areaIds->contains((int) $area->id))
                ->map(fn (ProjectArea $area): string => Str::lower(Str::squish($area->name)))
                ->filter(fn (string $name): bool => $name !== '')
                ->filter(fn (string $name): bool => $areas
                    ->filter(fn (ProjectArea $area): bool => Str::lower(Str::squish($area->name)) === $name)
                    ->count() > 1);

            if ($duplicateSelectedNames->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'documentPackOptionAreaIds' => 'Selected areas must have unique names before they can be reused across revisions or projects.',
                ]);
            }
        }

        return [
            'version' => 1,
            'area_scope' => $allAreasSelected ? 'all' : 'selected',
            'area_names' => $allAreasSelected
                ? []
                : $areas->whereIn('id', $areaIds)->pluck('name')->values()->all(),
            'include_datasheets' => $includeDatasheets,
        ];
    }

    /**
     * A missing configuration is a legacy all-area/no-datasheet item. It stays
     * generatable, but the UI marks it for review so its choices can be made explicit.
     *
     * @param  array<string, mixed>|null  $configuration
     * @return array{configured: bool, valid: bool, area_scope: string, area_ids: array<int, int>, area_names: array<int, string>, include_datasheets: bool, datasheet_count: int, message: string|null}
     */
    public function resolve(?array $configuration, ProjectRevision $revision): array
    {
        $areas = $revision->areas()
            ->orderBy('sort_order')
            ->get(['id', 'name']);
        $configured = is_array($configuration)
            && in_array($configuration['area_scope'] ?? null, ['all', 'selected'], true)
            && array_key_exists('include_datasheets', $configuration);
        $includeDatasheets = $configured && (bool) $configuration['include_datasheets'];

        if ($areas->isEmpty()) {
            if (! $configured || $configuration['area_scope'] === 'all') {
                return $this->validResult(
                    configured: $configured,
                    areaScope: 'all',
                    areaIds: [],
                    areaNames: [],
                    includeDatasheets: $includeDatasheets,
                    revision: $revision,
                    message: $configured ? null : 'Choose the areas and datasheet option for this item.',
                );
            }

            return $this->invalidResult(
                configured: $configured,
                includeDatasheets: $includeDatasheets,
                message: 'This revision has no areas. Add an area before generating the pack.',
            );
        }

        if (! $configured) {
            return $this->validResult(
                configured: false,
                areaScope: 'all',
                areaIds: [],
                areaNames: $areas->pluck('name')->all(),
                includeDatasheets: false,
                revision: $revision,
                message: 'Choose the areas and datasheet option for this item.',
            );
        }

        if ($configuration['area_scope'] === 'all') {
            return $this->validResult(
                configured: true,
                areaScope: 'all',
                areaIds: [],
                areaNames: $areas->pluck('name')->all(),
                includeDatasheets: $includeDatasheets,
                revision: $revision,
            );
        }

        $requestedNames = collect($configuration['area_names'] ?? [])
            ->filter(fn (mixed $name): bool => is_string($name) && filled(Str::squish($name)))
            ->map(fn (string $name): string => Str::squish($name))
            ->unique(fn (string $name): string => Str::lower($name))
            ->values();

        if ($requestedNames->isEmpty()) {
            return $this->invalidResult(
                configured: true,
                includeDatasheets: $includeDatasheets,
                message: 'The saved area selection is empty. Refresh this item.',
            );
        }

        $requestedKeys = $requestedNames->map(fn (string $name): string => Str::lower($name));
        $matchingAreas = $areas
            ->filter(fn (ProjectArea $area): bool => $requestedKeys->contains(Str::lower(Str::squish($area->name))))
            ->values();
        $matchedKeys = $matchingAreas
            ->pluck('name')
            ->map(fn (string $name): string => Str::lower(Str::squish($name)))
            ->unique()
            ->values();

        if ($requestedKeys->diff($matchedKeys)->isNotEmpty()) {
            return $this->invalidResult(
                configured: true,
                includeDatasheets: $includeDatasheets,
                message: 'One or more saved areas are not available in this revision. Refresh this item.',
            );
        }

        if ($matchingAreas->count() !== $requestedNames->count()) {
            return $this->invalidResult(
                configured: true,
                includeDatasheets: $includeDatasheets,
                message: 'One or more saved area names are ambiguous in this revision. Rename the duplicate areas, then refresh this item.',
            );
        }

        $areaIds = $matchingAreas->pluck('id')->map(fn (mixed $areaId): int => (int) $areaId)->all();

        return $this->validResult(
            configured: true,
            areaScope: 'selected',
            areaIds: $areaIds,
            areaNames: $matchingAreas->pluck('name')->all(),
            includeDatasheets: $includeDatasheets,
            revision: $revision,
        );
    }

    /**
     * @param  array<int, int>  $areaIds
     * @param  array<int, string>  $areaNames
     * @return array{configured: bool, valid: true, area_scope: string, area_ids: array<int, int>, area_names: array<int, string>, include_datasheets: bool, datasheet_count: int, message: string|null}
     */
    private function validResult(
        bool $configured,
        string $areaScope,
        array $areaIds,
        array $areaNames,
        bool $includeDatasheets,
        ProjectRevision $revision,
        ?string $message = null,
    ): array {
        return [
            'configured' => $configured,
            'valid' => true,
            'area_scope' => $areaScope,
            'area_ids' => $areaIds,
            'area_names' => $areaNames,
            'include_datasheets' => $includeDatasheets,
            'datasheet_count' => $includeDatasheets ? $this->datasheetCount($revision, $areaIds) : 0,
            'message' => $message,
        ];
    }

    /**
     * @return array{configured: bool, valid: false, area_scope: string, area_ids: array<int, int>, area_names: array<int, string>, include_datasheets: bool, datasheet_count: int, message: string}
     */
    private function invalidResult(bool $configured, bool $includeDatasheets, string $message): array
    {
        return [
            'configured' => $configured,
            'valid' => false,
            'area_scope' => 'selected',
            'area_ids' => [],
            'area_names' => [],
            'include_datasheets' => $includeDatasheets,
            'datasheet_count' => 0,
            'message' => $message,
        ];
    }

    /** @param array<int, int> $areaIds */
    private function datasheetCount(ProjectRevision $revision, array $areaIds): int
    {
        $codes = ProjectLine::query()
            ->whereHas('area', function ($query) use ($revision, $areaIds): void {
                $query->where('project_revision_id', $revision->id);

                if ($areaIds !== []) {
                    $query->whereIn('id', $areaIds);
                }
            })
            ->whereNotNull('code')
            ->pluck('code');

        return $codes
            ->filter(fn (mixed $code): bool => filled($code) && ProjectLine::specialOrderCodeFor((string) $code) === null)
            ->map(fn (string $code): string => Str::upper(Str::squish($code)))
            ->unique()
            ->count();
    }
}
