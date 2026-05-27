<?php

namespace App\Services;

use App\Models\Product;
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

            return $record;
        }, $rows);

        Product::query()->delete();

        foreach (array_chunk($records, 500) as $chunk) {
            Product::insert($chunk);
        }

        return count($records);
    }
}
