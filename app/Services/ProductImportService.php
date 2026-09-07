<?php

namespace App\Services;

use App\Enums\ProjectRevisionStatus;
use App\Models\AppSetting;
use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProductImportService
{
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
     * @throws RuntimeException if the API request, payload validation, or replacement fails.
     */
    public function import(): int
    {
        try {
            $response = $this->catalogueRequest()->post((string) config('services.product_catalogue.endpoint'));
        } catch (Throwable $exception) {
            Log::error('Product catalogue API could not be reached.', [
                'exception' => $exception,
            ]);

            throw new RuntimeException(
                'Product API could not be reached. The existing catalogue was left unchanged.',
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new RuntimeException("Product API request failed with status {$response->status()}.");
        }

        $payload = $response->json();

        if (! is_array($payload)
            || ! isset($payload['columns'], $payload['data'])
            || ! is_array($payload['columns'])
            || ! is_array($payload['data'])
            || array_any($payload['data'], fn (mixed $row): bool => ! is_array($row))) {
            throw new RuntimeException('Unexpected API response structure.');
        }

        $rows = $payload['data'];

        /** @var array<int, array<string, mixed>> $records */
        $records = array_values(array_filter(array_map(
            fn (array $row): ?array => $this->mapProductRow($row),
            $rows,
        )));
        $records = $this->uniqueRecordsBySku($records);

        if ($records === []) {
            throw new RuntimeException('Product API response contained no valid products.');
        }

        try {
            return DB::transaction(function () use ($records): int {
                Product::query()->delete();

                foreach (array_chunk($records, 500) as $chunk) {
                    Product::insert($chunk);
                }

                $this->populateMissingProjectLinePrices();
                $this->recordSuccessfulPull();

                return count($records);
            });
        } catch (Throwable $exception) {
            Log::error('Product catalogue replacement failed and was rolled back.', [
                'exception' => $exception,
                'product_count' => count($records),
            ]);

            throw new RuntimeException(
                'Product catalogue could not be replaced. The existing catalogue was left unchanged.',
                previous: $exception,
            );
        }
    }

    private function catalogueRequest(): PendingRequest
    {
        return Http::timeout(max(1, (int) config('services.product_catalogue.timeout', 30)))
            ->connectTimeout(max(1, (int) config('services.product_catalogue.connect_timeout', 5)))
            ->retry(
                max(1, (int) config('services.product_catalogue.retry_attempts', 3)),
                max(0, (int) config('services.product_catalogue.retry_delay_ms', 250)),
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && in_array($exception->response->status(), [408, 429], true))
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );
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
