<?php

namespace Tests\Feature;

use App\Enums\ProjectRevisionStatus;
use App\Models\Product;
use App\Models\Project;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private function apiResponse(array $extra = []): array
    {
        return array_merge([
            'columns' => ['id', 'product', 'type', 'sku', 'description', 'cost', 'site'],
            'data' => [
                ['id' => '1', 'site' => 'xcite', 'product' => 'Test Light', 'sku' => 'XC-001',
                    'cost' => '12.34', 'description' => 'A test product', 'type' => 'Downlights'],
                ['id' => '2', 'site' => 'Tamlite', 'product' => 'Another Light', 'sku' => 'TL-002',
                    'cost' => '', 'description' => 'Wide beam', 'type' => 'Floodlights'],
            ],
        ], $extra);
    }

    public function test_import_inserts_products_from_api(): void
    {
        Http::fake([
            '*' => Http::response($this->apiResponse(), 200),
        ]);

        $count = app(ProductImportService::class)->import();

        $this->assertSame(2, $count);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', [
            'sku' => 'XC-001',
            'product_name' => 'Test Light',
            'v_description' => 'A test product',
            'description' => 'A test product',
            'price' => '12.34',
        ]);
        $this->assertDatabaseHas('products', [
            'sku' => 'TL-002',
            'site' => 'Tamlite',
            'v_description' => 'Wide beam',
            'description' => 'Wide beam',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://tcms.tamlite.co.uk/api/luxquote_data'
            && $request->method() === 'POST');
    }

    public function test_import_maps_new_api_columns_to_local_product_columns(): void
    {
        Http::fake(['*' => Http::response($this->apiResponse(), 200)]);

        app(ProductImportService::class)->import();

        $this->assertDatabaseHas('products', [
            'sku' => 'XC-001',
            'product_name' => 'Test Light',
            'type_name' => 'Downlights',
            'price' => '12.34',
            'site' => 'xcite',
        ]);
    }

    public function test_import_uses_api_description_as_is(): void
    {
        Http::fake(['*' => Http::response($this->apiResponse([
            'data' => [
                ['id' => '1', 'site' => 'Tamlite', 'product' => 'Ignored prefix', 'sku' => 'TL-001',
                    'cost' => '1.00', 'description' => '  Keep this exact description  ', 'type' => 'Downlights'],
            ],
        ]), 200)]);

        app(ProductImportService::class)->import();

        $this->assertDatabaseHas('products', [
            'sku' => 'TL-001',
            'description' => '  Keep this exact description  ',
            'v_description' => '  Keep this exact description  ',
        ]);
    }

    public function test_import_skips_rows_without_skus(): void
    {
        Http::fake(['*' => Http::response($this->apiResponse([
            'data' => [
                ['id' => '1', 'site' => 'Tamlite', 'product' => 'Academy', 'sku' => null,
                    'cost' => '0', 'description' => 'Academy - Surface module', 'type' => 'Surface module'],
                ['id' => '2', 'site' => 'Tamlite', 'product' => 'Another Light', 'sku' => 'TL-002',
                    'cost' => '5.00', 'description' => 'Another Light Description', 'type' => 'Floodlights'],
            ],
        ]), 200)]);

        $count = app(ProductImportService::class)->import();

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('products', ['product_name' => 'Academy']);
        $this->assertDatabaseHas('products', ['sku' => 'TL-002']);
    }

    public function test_import_skips_duplicate_skus_from_api(): void
    {
        Http::fake(['*' => Http::response($this->apiResponse([
            'data' => [
                ['id' => '1', 'site' => 'Xcite', 'product' => 'Batten', 'sku' => 'XCSBT631WWP',
                    'cost' => '0', 'description' => 'Batten - PIR', 'type' => 'Surface Linear'],
                ['id' => '2', 'site' => 'Xcite', 'product' => 'Batten', 'sku' => 'XCSBT631WWP',
                    'cost' => '0', 'description' => 'Batten - PIR/Photocell', 'type' => 'Surface Linear'],
            ],
        ]), 200)]);

        $count = app(ProductImportService::class)->import();

        $this->assertSame(1, $count);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', [
            'sku' => 'XCSBT631WWP',
            'description' => 'Batten - PIR',
        ]);
        $this->assertDatabaseMissing('products', [
            'sku' => 'XCSBT631WWP',
            'description' => 'Batten - PIR/Photocell',
        ]);
    }

    public function test_import_converts_empty_nullable_values_to_null(): void
    {
        Http::fake(['*' => Http::response($this->apiResponse(), 200)]);

        app(ProductImportService::class)->import();

        $product = Product::where('sku', 'XC-001')->first();
        $this->assertNull($product->cut_out_mm);

        $productWithoutPrice = Product::where('sku', 'TL-002')->first();
        $this->assertNull($productWithoutPrice->price);
    }

    public function test_import_truncates_existing_products(): void
    {
        Product::factory()->count(5)->create();

        Http::fake(['*' => Http::response($this->apiResponse(), 200)]);

        app(ProductImportService::class)->import();

        $this->assertDatabaseCount('products', 2);
    }

    public function test_import_populates_blank_project_line_prices_from_matching_skus(): void
    {
        $project = Project::factory()->create();
        $area = $project->activeRevision->areas()->first();

        $blankPricedLine = $area->lines()->create([
            'code' => 'XC-001',
            'description' => 'Needs price',
            'qty' => 1,
            'unit_price' => null,
            'sort_order' => 0,
        ]);

        $manualPricedLine = $area->lines()->create([
            'code' => 'XC-001',
            'description' => 'Manual price',
            'qty' => 1,
            'unit_price' => 99.99,
            'sort_order' => 1,
        ]);

        Http::fake(['*' => Http::response($this->apiResponse(), 200)]);

        app(ProductImportService::class)->import();

        $this->assertSame('12.34', $blankPricedLine->fresh()->unit_price);
        $this->assertSame('99.99', $manualPricedLine->fresh()->unit_price);
    }

    public function test_import_populates_prices_on_validated_unapproved_revisions(): void
    {
        $project = Project::factory()->create();
        $project->activeRevision->update(['validated' => true]);

        $line = $project->activeRevision->areas()->first()->lines()->create([
            'code' => 'XC-001',
            'description' => 'Locked line',
            'qty' => 1,
            'unit_price' => null,
            'sort_order' => 0,
        ]);

        Http::fake(['*' => Http::response($this->apiResponse(), 200)]);

        app(ProductImportService::class)->import();

        $this->assertSame('12.34', $line->fresh()->unit_price);
    }

    public function test_import_does_not_populate_prices_on_approved_revisions(): void
    {
        $project = Project::factory()->create();
        $project->activeRevision->update([
            'validated' => true,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $line = $project->activeRevision->areas()->first()->lines()->create([
            'code' => 'XC-001',
            'description' => 'Locked line',
            'qty' => 1,
            'unit_price' => null,
            'sort_order' => 0,
        ]);

        Http::fake(['*' => Http::response($this->apiResponse(), 200)]);

        app(ProductImportService::class)->import();

        $this->assertNull($line->fresh()->unit_price);
    }

    public function test_import_throws_on_api_failure(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed with status 500/');

        app(ProductImportService::class)->import();
    }

    public function test_import_throws_on_unexpected_response_structure(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => true], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected API response structure.');

        app(ProductImportService::class)->import();
    }

    public function test_artisan_command_imports_products(): void
    {
        Http::fake(['*' => Http::response($this->apiResponse(), 200)]);

        $this->artisan('app:import-products')
            ->expectsOutput('Importing products...')
            ->expectsOutput('2 products imported successfully.')
            ->assertSuccessful();

        $this->assertDatabaseCount('products', 2);
    }

    public function test_artisan_command_fails_when_import_fails(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->artisan('app:import-products')
            ->expectsOutput('Importing products...')
            ->expectsOutput('Import failed: Product API request failed with status 500.')
            ->assertFailed();
    }
}
