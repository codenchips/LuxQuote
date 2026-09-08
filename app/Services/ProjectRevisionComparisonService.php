<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ProjectRevisionComparisonService
{
    /**
     * @return array{
     *     from: array{id: int, label: string},
     *     to: array{id: int, label: string},
     *     summary: array{areas_added: int, areas_removed: int, areas_changed: int, lines_added: int, lines_removed: int, lines_changed: int},
     *     areas: array<int, array<string, mixed>>
     * }
     */
    public function compare(ProjectRevision $fromRevision, ProjectRevision $toRevision, bool $includePricing): array
    {
        if ($fromRevision->project_id !== $toRevision->project_id) {
            throw new InvalidArgumentException('Revisions must belong to the same project.');
        }

        $fromRevision->loadMissing(['project', 'areas.lines']);
        $toRevision->loadMissing(['project', 'areas.lines']);

        $fromAreas = $this->areaSnapshots($fromRevision->areas, $includePricing, $fromRevision->project);
        $toAreas = $this->areaSnapshots($toRevision->areas, $includePricing, $toRevision->project);
        $areaKeys = $fromAreas->keys()->merge($toAreas->keys())->unique()->values();
        $areas = $areaKeys
            ->map(fn (string $key): array => $this->compareArea($fromAreas->get($key), $toAreas->get($key)))
            ->all();

        return [
            'from' => ['id' => $fromRevision->id, 'label' => $fromRevision->label()],
            'to' => ['id' => $toRevision->id, 'label' => $toRevision->label()],
            'summary' => [
                'areas_added' => collect($areas)->where('status', 'added')->count(),
                'areas_removed' => collect($areas)->where('status', 'removed')->count(),
                'areas_changed' => collect($areas)->where('status', 'changed')->count(),
                'lines_added' => collect($areas)->sum('summary.lines_added'),
                'lines_removed' => collect($areas)->sum('summary.lines_removed'),
                'lines_changed' => collect($areas)->sum('summary.lines_changed'),
            ],
            'areas' => $areas,
        ];
    }

    /**
     * @param  Collection<int, ProjectArea>  $areas
     * @return Collection<string, array{name: string, position: int, lines: Collection<string, array<string, mixed>>}>
     */
    private function areaSnapshots(Collection $areas, bool $includePricing, Project $project): Collection
    {
        $occurrences = [];

        return $areas
            ->sortBy('sort_order')
            ->values()
            ->mapWithKeys(function ($area, int $position) use (&$occurrences, $includePricing, $project): array {
                $identity = $this->normaliseIdentity($area->name);
                $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;
                $key = $identity.'#'.$occurrences[$identity];

                return [$key => [
                    'name' => (string) $area->name,
                    'position' => $position + 1,
                    'lines' => $this->lineSnapshots($area->lines, $includePricing, $project),
                ]];
            });
    }

    /**
     * @param  Collection<int, ProjectLine>  $lines
     * @return Collection<string, array<string, mixed>>
     */
    private function lineSnapshots(Collection $lines, bool $includePricing, Project $project): Collection
    {
        $occurrences = [];

        return $lines
            ->sortBy('sort_order')
            ->values()
            ->mapWithKeys(function (ProjectLine $line, int $position) use (&$occurrences, $includePricing, $project): array {
                $baseIdentity = filled($line->code)
                    ? 'sku:'.$this->normaliseIdentity($line->code)
                    : 'custom:'.$position;
                $occurrences[$baseIdentity] = ($occurrences[$baseIdentity] ?? 0) + 1;
                $key = $baseIdentity.'#'.$occurrences[$baseIdentity];

                $fields = [
                    'position' => ['label' => 'Order', 'raw' => $position + 1, 'value' => (string) ($position + 1)],
                    'ref' => ['label' => 'Ref', 'raw' => $line->ref, 'value' => $this->displayValue($line->ref)],
                    'code' => ['label' => 'SKU', 'raw' => $line->code, 'value' => $this->displayValue($line->code)],
                    'description' => ['label' => 'Description', 'raw' => $line->description, 'value' => $this->displayValue($line->description)],
                    'qty' => ['label' => 'Qty', 'raw' => $line->qty, 'value' => (string) $line->qty],
                    'type' => ['label' => 'Type', 'raw' => $line->type?->value, 'value' => $line->type?->label() ?? '—'],
                    'status' => ['label' => 'Status', 'raw' => $line->status, 'value' => $this->displayValue($line->status)],
                    'notes' => ['label' => 'Notes', 'raw' => $line->notes, 'value' => $this->displayValue($line->notes)],
                ];

                if ($includePricing) {
                    $fields['unit_price'] = [
                        'label' => 'Unit price',
                        'raw' => $line->unit_price,
                        'value' => $this->currencyValue($line, $project),
                    ];
                }

                if ($includePricing && $project->has_cover) {
                    $coverOne = $this->effectiveCoverValue($line, $project, 'cover_1');
                    $coverTwo = $this->effectiveCoverValue($line, $project, 'cover_2');
                    $coverThree = $this->effectiveCoverValue($line, $project, 'cover_3');
                    $fields += [
                        'cover_1' => ['label' => 'Cover 1', 'raw' => $coverOne, 'value' => $this->percentageValue($coverOne)],
                        'cover_2' => ['label' => 'Cover 2', 'raw' => $coverTwo, 'value' => $this->percentageValue($coverTwo)],
                        'cover_3' => ['label' => 'Cover 3', 'raw' => $coverThree, 'value' => $this->percentageValue($coverThree)],
                    ];
                }

                return [$key => [
                    'label' => filled($line->code) ? (string) $line->code : ($line->description ?: 'Untitled line'),
                    'fields' => $fields,
                ]];
            });
    }

    /**
     * @param  array{name: string, position: int, lines: Collection<string, array<string, mixed>>}|null  $fromArea
     * @param  array{name: string, position: int, lines: Collection<string, array<string, mixed>>}|null  $toArea
     * @return array<string, mixed>
     */
    private function compareArea(?array $fromArea, ?array $toArea): array
    {
        $fromLines = $fromArea['lines'] ?? collect();
        $toLines = $toArea['lines'] ?? collect();
        $lineKeys = $fromLines->keys()
            ->merge($toLines->keys())
            ->unique()
            ->values();
        $lines = $lineKeys
            ->map(fn (string $key): array => $this->compareLine(
                $fromLines->get($key),
                $toLines->get($key),
            ))
            ->all();

        $status = match (true) {
            $fromArea === null => 'added',
            $toArea === null => 'removed',
            $fromArea['name'] !== $toArea['name']
                || $fromArea['position'] !== $toArea['position']
                || collect($lines)->contains(fn (array $line): bool => $line['status'] !== 'unchanged') => 'changed',
            default => 'unchanged',
        };

        return [
            'status' => $status,
            'from_name' => $fromArea['name'] ?? null,
            'to_name' => $toArea['name'] ?? null,
            'from_position' => $fromArea['position'] ?? null,
            'to_position' => $toArea['position'] ?? null,
            'summary' => [
                'lines_added' => collect($lines)->where('status', 'added')->count(),
                'lines_removed' => collect($lines)->where('status', 'removed')->count(),
                'lines_changed' => collect($lines)->where('status', 'changed')->count(),
            ],
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $fromLine
     * @param  array<string, mixed>|null  $toLine
     * @return array<string, mixed>
     */
    private function compareLine(?array $fromLine, ?array $toLine): array
    {
        $fieldKeys = collect(array_keys($fromLine['fields'] ?? []))
            ->merge(array_keys($toLine['fields'] ?? []))
            ->unique();
        $fields = $fieldKeys
            ->mapWithKeys(function (string $key) use ($fromLine, $toLine): array {
                $fromField = $fromLine['fields'][$key] ?? null;
                $toField = $toLine['fields'][$key] ?? null;

                return [$key => [
                    'label' => $fromField['label'] ?? $toField['label'],
                    'from' => $fromField['value'] ?? '—',
                    'to' => $toField['value'] ?? '—',
                    'changed' => ($fromField['raw'] ?? null) !== ($toField['raw'] ?? null),
                ]];
            })
            ->all();
        $status = match (true) {
            $fromLine === null => 'added',
            $toLine === null => 'removed',
            collect($fields)->contains('changed', true) => 'changed',
            default => 'unchanged',
        };

        return [
            'status' => $status,
            'from_label' => $fromLine['label'] ?? null,
            'to_label' => $toLine['label'] ?? null,
            'fields' => $fields,
        ];
    }

    private function normaliseIdentity(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $value)) ?: '(blank)');
    }

    private function displayValue(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }

    private function currencyValue(ProjectLine $line, Project $project): string
    {
        if ($line->unit_price === null) {
            return '—';
        }

        return $project->formatCurrency((float) $line->unit_price);
    }

    private function percentageValue(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 2).'%';
    }

    private function effectiveCoverValue(ProjectLine $line, Project $project, string $field): ?string
    {
        $cover = $line->getAttribute($field) ?? $project->getAttribute($field);

        return $cover === null || $cover === ''
            ? null
            : number_format((float) $cover, 2, '.', '');
    }
}
