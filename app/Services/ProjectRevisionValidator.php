<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProjectRevisionValidator
{
    /**
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     area: string,
     *     code: string,
     *     description: string,
     *     message: string,
     *     line_ids: array<int, int>,
     *     approved: bool
     * }>
     */
    public function issues(ProjectRevision $revision): array
    {
        $areas = ProjectArea::query()
            ->where('project_revision_id', $revision->id)
            ->with(['lines' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $productSkus = Product::query()
            ->whereIn('sku', $areas->flatMap->lines->pluck('code')->filter()->unique())
            ->pluck('sku')
            ->mapWithKeys(fn (string $sku): array => [$this->normaliseSku($sku) => true]);

        return $areas
            ->flatMap(fn (ProjectArea $area): array => [
                ...$this->duplicateSkuIssues($area),
                ...$this->missingProductIssues($area, $productSkus),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     area: string,
     *     code: string,
     *     description: string,
     *     message: string,
     *     line_ids: array<int, int>,
     *     approved: bool
     * }>
     */
    public function unresolvedIssues(ProjectRevision $revision): array
    {
        return array_values(array_filter(
            $this->issues($revision),
            fn (array $issue): bool => ! $issue['approved'],
        ));
    }

    public function syncValidationStatus(ProjectRevision $revision): bool
    {
        $unresolvedIssues = $this->unresolvedIssues($revision);
        $unresolvedLineIds = collect($unresolvedIssues)->flatMap(fn (array $issue): array => $issue['line_ids'])->unique();

        $this->lineQuery($revision)
            ->where('approved', false)
            ->when($unresolvedLineIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('id', $unresolvedLineIds))
            ->update([
                'approved' => true,
                'approved_at' => now(),
                'approved_by' => null,
            ]);

        $validated = $unresolvedIssues === []
            && $this->lineQuery($revision)->where('approved', false)->doesntExist();

        if ($revision->validated === $validated) {
            return $validated;
        }

        $revision->update([
            'validated' => $validated,
            'validated_at' => $validated ? now() : null,
            'validated_by' => $validated ? auth()->id() : null,
        ]);

        return $validated;
    }

    private function lineQuery(ProjectRevision $revision): Builder
    {
        return ProjectLine::query()
            ->whereHas('area', fn (Builder $query) => $query->where('project_revision_id', $revision->id));
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     area: string,
     *     code: string,
     *     description: string,
     *     message: string,
     *     line_ids: array<int, int>,
     *     approved: bool
     * }>
     */
    private function duplicateSkuIssues(ProjectArea $area): array
    {
        return $area->lines
            ->filter(fn (ProjectLine $line): bool => filled($line->code))
            ->groupBy(fn (ProjectLine $line): string => $this->normaliseSku($line->code))
            ->filter(fn (Collection $lines): bool => $lines->count() > 1)
            ->map(function (Collection $lines, string $normalisedSku) use ($area): array {
                /** @var ProjectLine $line */
                $line = $lines->first();

                return $this->issue(
                    key: "duplicate-{$area->id}-{$normalisedSku}",
                    type: 'duplicate_sku',
                    area: $area,
                    line: $line,
                    lines: $lines,
                    message: "SKU \"{$line->code}\" appears {$lines->count()} times in this area.",
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, bool>  $productSkus
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     area: string,
     *     code: string,
     *     description: string,
     *     message: string,
     *     line_ids: array<int, int>,
     *     approved: bool
     * }>
     */
    private function missingProductIssues(ProjectArea $area, Collection $productSkus): array
    {
        return $area->lines
            ->filter(fn (ProjectLine $line): bool => filled($line->code))
            ->reject(fn (ProjectLine $line): bool => $productSkus->has($this->normaliseSku($line->code)))
            ->map(fn (ProjectLine $line): array => $this->issue(
                key: "missing-product-{$line->id}",
                type: 'missing_product',
                area: $area,
                line: $line,
                lines: collect([$line]),
                message: "SKU \"{$line->code}\" was not found in the product catalogue.",
            ))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ProjectLine>  $lines
     * @return array{
     *     key: string,
     *     type: string,
     *     area: string,
     *     code: string,
     *     description: string,
     *     message: string,
     *     line_ids: array<int, int>,
     *     approved: bool
     * }
     */
    private function issue(
        string $key,
        string $type,
        ProjectArea $area,
        ProjectLine $line,
        Collection $lines,
        string $message,
    ): array {
        return [
            'key' => $key,
            'type' => $type,
            'area' => $area->name,
            'code' => $line->code ?? '',
            'description' => $line->description,
            'message' => $message,
            'line_ids' => $lines->pluck('id')->all(),
            'approved' => $lines->every(
                fn (ProjectLine $line): bool => $line->approved && $line->approved_by !== null
            ),
        ];
    }

    private function normaliseSku(string $sku): string
    {
        return mb_strtoupper(trim($sku));
    }
}
