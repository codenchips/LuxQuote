<?php

namespace App\Services;

use App\Enums\ProjectRevisionStatus;
use App\Models\AppSetting;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductImportService
{
    private const API_URL = 'https://tcms.tamlite.co.uk/api/luxquote_data';

    public const LastPulledAtSettingKey = 'products_last_pulled_at';

    private const MaxProductPrice = 99999999.99;

    private const PRODUCT_SPEC_COLUMNS = [
        'length_mm',
        'width_mm',
        'depth_mm',
        'diameter_mm',
        'cut_out_mm',
        'weight_kg',
        'luminaire_wattage_w',
        'lumens_lm',
        'efficacy_llm_w',
        'beam_angle_fwhm',
        'emergency_lumen_output',
        'power',
        'em_power',
        'cct_k',
        'colour_temp',
        'cri',
        'dali',
        'vision_type',
        'emergency_type',
        'ip_rating',
        'ik_rating',
        'electrical_class',
        'rl_ral',
    ];

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

        $rows = $payload['data'];

        /** @var array<int, array<string, mixed>> $records */
        $records = array_values(array_filter(array_map(
            fn (array $row): ?array => $this->mapProductRow($row),
            $rows,
        )));
        $records = $this->uniqueRecordsBySku($records);

        Product::query()->delete();

        foreach (array_chunk($records, 500) as $chunk) {
            Product::insert($chunk);
        }

        $this->populateMissingProjectLinePrices();
        $this->recordSuccessfulPull();

        return count($records);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapProductRow(array $row): ?array
    {
        $sku = $this->normaliseNullableString($row['sku'] ?? null);
        $productName = $this->normaliseNullableString($row['product'] ?? null);

        if ($sku === null || $productName === null) {
            return null;
        }

        $record = [
            'site' => $this->normaliseNullableString($row['site'] ?? null),
            'product_name' => $productName,
            'sku' => $sku,
            'price' => $this->normaliseProductPrice($row['cost'] ?? null),
            'description' => $row['description'] ?? null,
            'v_description' => $row['description'] ?? null,
            'type_name' => $this->normaliseNullableString($row['type'] ?? null),
        ];

        foreach (self::PRODUCT_SPEC_COLUMNS as $column) {
            $record[$column] = null;
        }

        return $record;
    }

    private function normaliseNullableValue(mixed $value): mixed
    {
        return $value !== '' ? $value : null;
    }

    private function normaliseNullableString(mixed $value): ?string
    {
        $value = $this->normaliseNullableValue($value);

        if ($value === null) {
            return null;
        }

        return mb_substr((string) $value, 0, 255);
    }

    private function normaliseProductPrice(mixed $value): ?string
    {
        $value = $this->normaliseNullableValue($value);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return number_format(min(self::MaxProductPrice, max(0, (float) $value)), 2, '.', '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function uniqueRecordsBySku(array $records): array
    {
        $seenSkus = [];
        $uniqueRecords = [];

        foreach ($records as $record) {
            $sku = (string) $record['sku'];

            if (isset($seenSkus[$sku])) {
                continue;
            }

            $seenSkus[$sku] = true;
            $uniqueRecords[] = $record;
        }

        return $uniqueRecords;
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

    private function recordSuccessfulPull(): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => self::LastPulledAtSettingKey],
            ['value' => ['pulled_at' => now()->toIso8601String()]],
        );
    }
}
