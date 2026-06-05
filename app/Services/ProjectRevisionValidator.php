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
     *     approved: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null
     * }>
     */
    public function issues(ProjectRevision $revision): array
    {
        $areas = ProjectArea::query()
            ->where('project_revision_id', $revision->id)
            ->with(['lines' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $productsBySku = Product::query()
            ->whereIn('sku', $areas->flatMap->lines->pluck('code')->filter()->unique())
            ->get(['sku', 'price'])
            ->mapWithKeys(fn (Product $product): array => [$this->normaliseSku($product->sku) => $product]);

        return $areas
            ->flatMap(fn (ProjectArea $area): array => [
                ...$this->duplicateSkuIssues($area),
                ...$this->missingProductIssues($area, $productsBySku),
                ...$this->priceMismatchIssues($area, $productsBySku),
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
     *     approved: bool,
     *     rrp?: string|null,
     *     quote_price?: string|null
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
     *     approved: bool,
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
     *     rrp?: string|null,
     *     quote_price?: string|null
     * }>
     */
    private function missingProductIssues(ProjectArea $area, Collection $productsBySku): array
    {
        return $area->lines
            ->filter(fn (ProjectLine $line): bool => filled($line->code))
            ->reject(fn (ProjectLine $line): bool => $productsBySku->has($this->normaliseSku($line->code)))
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
     *     rrp: string|null,
     *     quote_price: string|null
     * }>
     */
    private function priceMismatchIssues(ProjectArea $area, Collection $productsBySku): array
    {
        return $area->lines
            ->filter(fn (ProjectLine $line): bool => filled($line->code))
            ->map(function (ProjectLine $line) use ($area, $productsBySku): ?array {
                /** @var Product|null $product */
                $product = $productsBySku->get($this->normaliseSku($line->code));

                if ($product === null || $product->price === null || $this->pricesMatch($line->unit_price, $product->price)) {
                    return null;
                }

                return $this->issue(
                    key: "price-mismatch-{$line->id}",
                    type: 'price_mismatch',
                    area: $area,
                    line: $line,
                    lines: collect([$line]),
                    message: "Quote price for SKU \"{$line->code}\" does not match the product RRP.",
                    extra: [
                        'rrp' => $product->price,
                        'quote_price' => $line->unit_price,
                    ],
                );
            })
            ->filter()
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
     *     rrp?: string|null,
     *     quote_price?: string|null
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
                fn (ProjectLine $line): bool => $line->approved && $line->approved_by !== null
            ),
        ] + $extra;
    }

    private function normaliseSku(string $sku): string
    {
        return mb_strtoupper(trim($sku));
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
