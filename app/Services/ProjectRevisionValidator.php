<?php

namespace App\Services;

use App\Enums\ProjectRevisionStatus;
use App\Models\Product;
use App\Models\Project;
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
     *     approved: bool,
     *     flagged: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null,
     *     unit_price?: string|null,
     *     net_price?: float|null,
     *     total_price?: float|null,
     *     cover_values?: array{cover_1: string|null, cover_2: string|null, cover_3: string|null},
     *     cover_defaults?: array{cover_1: string|null, cover_2: string|null, cover_3: string|null}
     * }>
     */
    public function issues(ProjectRevision $revision): array
    {
        $revision->loadMissing('project');

        $areas = ProjectArea::query()
            ->where('project_revision_id', $revision->id)
            ->with(['lines' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $productsBySku = Product::query()
            ->whereIn('sku', $areas->flatMap->lines->pluck('code')->filter()->unique())
            ->get(['sku', 'price'])
            ->mapWithKeys(fn (Product $product): array => [$this->normaliseSku($product->sku) => $product]);

        $projectHasCover = $this->projectHasCover($revision);
        $projectCoverDefaults = $this->projectCoverDefaults($revision);

        return $areas
            ->flatMap(function (ProjectArea $area) use ($revision, $productsBySku, $projectHasCover, $projectCoverDefaults): array {
                $duplicateIssues = $this->duplicateSkuIssues($area);
                $priceReviewIssues = $this->priceReviewIssues($area, $productsBySku);
                $coverIssues = $projectHasCover && $revision->project !== null
                    ? $this->coverMismatchIssues($area, $projectCoverDefaults, $productsBySku, $revision->project)
                    : [];
                $coveredLineIds = collect([...$duplicateIssues, ...$priceReviewIssues, ...$coverIssues])
                    ->flatMap(fn (array $issue): array => $issue['line_ids'])
                    ->unique();

                return $this->sortAreaIssues($area, [
                    ...$duplicateIssues,
                    ...$priceReviewIssues,
                    ...$coverIssues,
                    ...$this->manualFlaggedIssues($area, $coveredLineIds),
                ]);
            })
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
     *     approved: bool,
     *     flagged: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null,
     *     flag_note?: string|null
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
            if (! $validated && $revision->status === ProjectRevisionStatus::Approved) {
                $revision->update([
                    'status' => ProjectRevisionStatus::Draft,
                ]);
            }

            return $validated;
        }

        $revision->update([
            'validated' => $validated,
            'validated_at' => $validated ? now() : null,
            'validated_by' => $validated ? auth()->id() : null,
            'status' => $validated ? $revision->status : ProjectRevisionStatus::Draft,
        ]);

        return $validated;
    }

    private function lineQuery(ProjectRevision $revision): Builder
    {
        return ProjectLine::query()
            ->whereHas('area', fn (Builder $query) => $query->where('project_revision_id', $revision->id));
    }

    /**
     * @param  array<int, array{type: string, line_ids: array<int, int>}>  $issues
     * @return array<int, array{type: string, line_ids: array<int, int>}>
     */
    private function sortAreaIssues(ProjectArea $area, array $issues): array
    {
        $lineOrderById = $area->lines
            ->mapWithKeys(fn (ProjectLine $line): array => [$line->id => (int) $line->sort_order])
            ->all();

        $typeOrder = [
            'duplicate_sku' => 0,
            'price_mismatch' => 1,
            'cover_mismatch' => 2,
            'manual_flag' => 3,
        ];

        usort($issues, function (array $firstIssue, array $secondIssue) use ($lineOrderById, $typeOrder): int {
            $firstLineOrder = $this->issueLineOrder($firstIssue, $lineOrderById);
            $secondLineOrder = $this->issueLineOrder($secondIssue, $lineOrderById);

            if ($firstLineOrder !== $secondLineOrder) {
                return $firstLineOrder <=> $secondLineOrder;
            }

            return ($typeOrder[$firstIssue['type']] ?? 99) <=> ($typeOrder[$secondIssue['type']] ?? 99);
        });

        return $issues;
    }

    /**
     * @param  array{line_ids: array<int, int>}  $issue
     * @param  array<int, int>  $lineOrderById
     */
    private function issueLineOrder(array $issue, array $lineOrderById): int
    {
        return collect($issue['line_ids'])
            ->map(fn (int $lineId): int => $lineOrderById[$lineId] ?? PHP_INT_MAX)
            ->min() ?? PHP_INT_MAX;
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
     *     approved: bool,
     *     flagged: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null
     * }>
     */
    private function manualFlaggedIssues(ProjectArea $area, Collection $coveredLineIds): array
    {
        return $area->lines
            ->filter(fn (ProjectLine $line): bool => $line->validation_flagged && ! $coveredLineIds->contains($line->id))
            ->map(fn (ProjectLine $line): array => $this->issue(
                key: "manual-flag-{$line->id}",
                type: 'manual_flag',
                area: $area,
                line: $line,
                lines: collect([$line]),
                message: $this->manualFlaggedMessage($line),
            ))
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
     *     approved: bool,
     *     flagged: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null
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
     * @param  Collection<string, Product>  $productsBySku
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     area: string,
     *     code: string,
     *     description: string,
     *     message: string,
     *     line_ids: array<int, int>,
     *     approved: bool,
     *     flagged: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null
     * }>
     */
    private function priceReviewIssues(ProjectArea $area, Collection $productsBySku): array
    {
        return $area->lines
            ->filter(fn (ProjectLine $line): bool => filled($line->code))
            ->map(function (ProjectLine $line) use ($area, $productsBySku): ?array {
                /** @var Product|null $product */
                $product = $productsBySku->get($this->normaliseSku($line->code));

                if ($product !== null && $product->price !== null && $this->pricesMatch($line->unit_price, $product->price)) {
                    return null;
                }

                return $this->issue(
                    key: "price-mismatch-{$line->id}",
                    type: 'price_mismatch',
                    area: $area,
                    line: $line,
                    lines: collect([$line]),
                    message: $this->priceReviewMessage($line, $product),
                    extra: [
                        'rrp' => $product?->price,
                        'quote_price' => $line->unit_price,
                    ],
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array{cover_1: string|null, cover_2: string|null, cover_3: string|null}  $projectCoverDefaults
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     area: string,
     *     code: string,
     *     description: string,
     *     message: string,
     *     line_ids: array<int, int>,
     *     approved: bool,
     *     flagged: bool,
     *     quote_price?: string|null,
     *     rrp?: string|null,
     *     unit_price?: string|null,
     *     net_price?: float|null,
     *     total_price?: float|null,
     *     cover_values: array{cover_1: string|null, cover_2: string|null, cover_3: string|null},
     *     cover_defaults: array{cover_1: string|null, cover_2: string|null, cover_3: string|null}
     * }>
     */
    private function coverMismatchIssues(ProjectArea $area, array $projectCoverDefaults, Collection $productsBySku, Project $project): array
    {
        return $area->lines
            ->filter(fn (ProjectLine $line): bool => $this->lineCoverValues($line, $projectCoverDefaults) !== $projectCoverDefaults)
            ->map(function (ProjectLine $line) use ($area, $productsBySku, $projectCoverDefaults, $project): array {
                /** @var Product|null $product */
                $product = filled($line->code)
                    ? $productsBySku->get($this->normaliseSku($line->code))
                    : null;

                return $this->issue(
                    key: "cover-mismatch-{$line->id}",
                    type: 'cover_mismatch',
                    area: $area,
                    line: $line,
                    lines: collect([$line]),
                    message: "Cover values for SKU \"{$line->code}\" differ from the project defaults.",
                    extra: [
                        'rrp' => $product?->price,
                        'unit_price' => $line->unit_price,
                        'net_price' => $line->netUnitPriceForProject($project),
                        'total_price' => $line->totalUnitPriceForProject($project),
                        'quote_price' => $line->unit_price,
                        'cover_values' => $this->lineCoverValues($line, $projectCoverDefaults),
                        'cover_defaults' => $projectCoverDefaults,
                    ],
                );
            })
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
     *     approved: bool,
     *     flagged: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null,
     *     flag_note?: string|null,
     *     cover_values?: array{cover_1: string|null, cover_2: string|null, cover_3: string|null},
     *     cover_defaults?: array{cover_1: string|null, cover_2: string|null, cover_3: string|null}
     * }
     */
    private function issue(
        string $key,
        string $type,
        ProjectArea $area,
        ProjectLine $line,
        Collection $lines,
        string $message,
        array $extra = [],
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
                fn (ProjectLine $line): bool => $line->approved
                    && $line->approved_by !== null
                    && str_contains((string) $line->validation_note, $this->issueApprovalNote($message))
            ),
            'flagged' => $lines->contains(fn (ProjectLine $line): bool => $line->validation_flagged),
            'flag_note' => $this->flagNote($lines),
        ] + $extra;
    }

    private function issueApprovalNote(string $message): string
    {
        return 'Approved: '.$message;
    }

    private function manualFlaggedMessage(ProjectLine $line): string
    {
        $message = "SKU \"{$line->code}\" has been manually flagged for review.";

        if (filled($line->validation_note)) {
            return $message.' '.$line->validation_note;
        }

        return $message;
    }

    /**
     * @param  Collection<int, ProjectLine>  $lines
     */
    private function flagNote(Collection $lines): ?string
    {
        $line = $lines->first(fn (ProjectLine $line): bool => $line->validation_flagged && filled($line->validation_note));

        return $line instanceof ProjectLine ? (string) $line->validation_note : null;
    }

    private function normaliseSku(string $sku): string
    {
        return mb_strtoupper(trim($sku));
    }

    /**
     * @return array{cover_1: string|null, cover_2: string|null, cover_3: string|null}
     */
    private function projectCoverDefaults(ProjectRevision $revision): array
    {
        $revision->loadMissing('project');

        return [
            'cover_1' => $this->normaliseCover($revision->project?->cover_1),
            'cover_2' => $this->normaliseCover($revision->project?->cover_2),
            'cover_3' => $this->normaliseCover($revision->project?->cover_3),
        ];
    }

    private function projectHasCover(ProjectRevision $revision): bool
    {
        $revision->loadMissing('project');

        return (bool) $revision->project?->has_cover;
    }

    /**
     * @param  array{cover_1: string|null, cover_2: string|null, cover_3: string|null}  $projectCoverDefaults
     * @return array{cover_1: string|null, cover_2: string|null, cover_3: string|null}
     */
    private function lineCoverValues(ProjectLine $line, array $projectCoverDefaults): array
    {
        return [
            'cover_1' => $this->normaliseCover($line->cover_1) ?? $projectCoverDefaults['cover_1'],
            'cover_2' => $this->normaliseCover($line->cover_2) ?? $projectCoverDefaults['cover_2'],
            'cover_3' => $this->normaliseCover($line->cover_3) ?? $projectCoverDefaults['cover_3'],
        ];
    }

    private function normaliseCover(mixed $cover): ?string
    {
        if ($cover === null || $cover === '') {
            return null;
        }

        return number_format((float) $cover, 2, '.', '');
    }

    private function priceReviewMessage(ProjectLine $line, ?Product $product): string
    {
        if ($product === null) {
            return $line->unit_price === null
                ? "SKU \"{$line->code}\" was not found in the product catalogue and has no quote price."
                : "SKU \"{$line->code}\" was not found in the product catalogue. Review the quote price before approving.";
        }

        if ($product->price === null) {
            return $line->unit_price === null
                ? "SKU \"{$line->code}\" has no product RRP and no quote price."
                : "SKU \"{$line->code}\" has no product RRP. Review the quote price before approving.";
        }

        if ($line->unit_price === null) {
            return "SKU \"{$line->code}\" has no quote price.";
        }

        return "Quote price for SKU \"{$line->code}\" does not match the product RRP.";
    }

    private function pricesMatch(string|float|int|null $quotePrice, string|float|int|null $rrp): bool
    {
        if ($quotePrice === null || $rrp === null) {
            return $quotePrice === $rrp;
        }

        return $this->normalisePrice($quotePrice) === $this->normalisePrice($rrp);
    }

    private function normalisePrice(string|float|int $price): string
    {
        return number_format((float) $price, 2, '.', '');
    }
}
