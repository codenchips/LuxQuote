<?php

namespace App\Services;

use App\Enums\ProjectRevisionStatus;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductImportService
{
    private const API_URL = 'https://tcms.tamlite.co.uk/api/product_data';

    /**
     * Fetch products from the external API and replace the local product table.
     *
     * @return int The number of products imported.
     *
     * @throws RuntimeException if the API request fails.
     */
    public function import(): int
    {
        $response = Http::timeout(30)->post(self::API_URL);

        if ($response->failed()) {
            throw new RuntimeException("Product API request failed with status {$response->status()}.");
        }

        $payload = $response->json();

        if (! isset($payload['columns'], $payload['data'])) {
            throw new RuntimeException('Unexpected API response structure.');
        }

        $columns = $payload['columns'];
        $rows = $payload['data'];

        /** @var array<int, array<string, mixed>> $records */
        $records = array_map(function (array $row) use ($columns): array {
            $record = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $record[$column === 'SKU' ? 'sku' : $column] = $value !== '' ? $value : null;
            }

            $record['description'] = $this->buildDescription($record);

            return $record;
        }, $rows);

        Product::query()->delete();

        foreach (array_chunk($records, 500) as $chunk) {
            Product::insert($chunk);
        }

        $this->populateMissingProjectLinePrices();

        return count($records);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function buildDescription(array $record): ?string
    {
        $productName = trim((string) ($record['product_name'] ?? ''));
        $visualDescription = trim((string) ($record['v_description'] ?? $record['description'] ?? ''));

        if (strtolower((string) ($record['site'] ?? '')) === 'xcite') {
            return $visualDescription !== '' ? $visualDescription : null;
        }

        $description = trim(implode(' ', array_filter([$productName, $visualDescription])));

        return $description !== '' ? $description : null;
    }

    private function populateMissingProjectLinePrices(): void
    {
        DB::table('project_lines')
            ->join('project_areas', 'project_lines.project_area_id', '=', 'project_areas.id')
            ->join('project_revisions', 'project_areas.project_revision_id', '=', 'project_revisions.id')
            ->join('products', 'project_lines.code', '=', 'products.sku')
            ->whereNull('project_lines.unit_price')
            ->whereNotNull('products.price')
            ->where('project_revisions.status', '!=', ProjectRevisionStatus::Approved->value)
            ->update([
                'project_lines.unit_price' => DB::raw('products.price'),
                'project_lines.updated_at' => now(),
            ]);
    }
}
