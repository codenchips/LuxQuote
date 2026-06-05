<?php

namespace Tests\Feature;

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
            'columns' => ['site', 'product_name', 'SKU', 'price', 'description', 'type_name',
                'length_mm', 'width_mm', 'depth_mm', 'diameter_mm', 'cut_out_mm',
                'weight_kg', 'luminaire_wattage_w', 'lumens_lm', 'efficacy_llm_w',
                'beam_angle_fwhm', 'emergency_lumen_output', 'power', 'em_power',
                'cct_k', 'colour_temp', 'cri', 'dali', 'vision_type', 'emergency_type',
                'ip_rating', 'ik_rating', 'electrical_class', 'rl_ral'],
            'data' => [
                ['site' => 'xcite', 'product_name' => 'Test Light', 'SKU' => 'XC-001',
                    'price' => '12.34', 'description' => 'A test product', 'type_name' => 'Downlights',
                    'length_mm' => '100', 'width_mm' => null, 'depth_mm' => null,
                    'diameter_mm' => null, 'cut_out_mm' => '', 'weight_kg' => '1.5',
                    'luminaire_wattage_w' => '10W', 'lumens_lm' => '800',
                    'efficacy_llm_w' => '80', 'beam_angle_fwhm' => null,
                    'emergency_lumen_output' => null, 'power' => null, 'em_power' => null,
                    'cct_k' => '4000K', 'colour_temp' => 'NW', 'cri' => '80',
                    'dali' => null, 'vision_type' => null, 'emergency_type' => null,
                    'ip_rating' => 'IP44', 'ik_rating' => null,
                    'electrical_class' => 'Class 2', 'rl_ral' => null],
                ['site' => 'tamlite', 'product_name' => 'Another Light', 'SKU' => 'TL-002',
                    'price' => '', 'description' => null, 'type_name' => 'Floodlights',
                    'length_mm' => null, 'width_mm' => null, 'depth_mm' => null,
                    'diameter_mm' => null, 'cut_out_mm' => null, 'weight_kg' => null,
                    'luminaire_wattage_w' => null, 'lumens_lm' => null,
                    'efficacy_llm_w' => null, 'beam_angle_fwhm' => null,
                    'emergency_lumen_output' => null, 'power' => null, 'em_power' => null,
                    'cct_k' => null, 'colour_temp' => null, 'cri' => null,
                    'dali' => null, 'vision_type' => null, 'emergency_type' => null,
                    'ip_rating' => null, 'ik_rating' => null,
                    'electrical_class' => null, 'rl_ral' => null],
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
        $this->assertDatabaseHas('products', ['sku' => 'XC-001', 'product_name' => 'Test Light', 'price' => '12.34']);
        $this->assertDatabaseHas('products', ['sku' => 'TL-002', 'site' => 'tamlite']);
    }

    public function test_import_maps_sku_column_to_lowercase(): void
    {
        Http::fake(['*' => Http::response($this->apiResponse(), 200)]);

        app(ProductImportService::class)->import();

        $this->assertDatabaseHas('products', ['sku' => 'XC-001']);
    }

    public function test_import_converts_empty_strings_to_null(): void
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

    public function test_import_does_not_populate_prices_on_validated_revisions(): void
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
}
