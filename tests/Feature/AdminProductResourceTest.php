<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AdminProductResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_products(): void
    {
        $admin = User::factory()->create();
        Product::factory()->count(3)->create();

        $this->actingAs($admin);

        Livewire::test(ListProducts::class)
            ->assertSuccessful();
    }

    public function test_products_table_shows_required_columns(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create([
            'site' => 'xcite',
            'product_name' => 'My Lamp',
            'description' => 'My visual lamp description',
            'sku' => 'XC-TEST-01',
            'price' => 42.50,
            'type_name' => 'Downlights',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListProducts::class)
            ->assertSee($product->description)
            ->assertSee($product->sku)
            ->assertSee('42.50')
            ->assertSee($product->site)
            ->assertSee($product->type_name);
    }

    public function test_fetch_products_action_imports_and_shows_notification(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            '*' => Http::response([
                'columns' => ['site', 'product_name', 'SKU', 'price', 'v_description', 'type_name',
                    'length_mm', 'width_mm', 'depth_mm', 'diameter_mm', 'cut_out_mm',
                    'weight_kg', 'luminaire_wattage_w', 'lumens_lm', 'efficacy_llm_w',
                    'beam_angle_fwhm', 'emergency_lumen_output', 'power', 'em_power',
                    'cct_k', 'colour_temp', 'cri', 'dali', 'vision_type', 'emergency_type',
                    'ip_rating', 'ik_rating', 'electrical_class', 'rl_ral'],
                'data' => [
                    ['site' => 'xcite', 'product_name' => 'Lamp A', 'SKU' => 'XC-A',
                        'price' => '15.99', 'v_description' => 'Lamp A visual description', 'type_name' => 'Downlights',
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
            ], 200),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListProducts::class)
            ->callAction('fetchProducts')
            ->assertHasNoActionErrors();

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['sku' => 'XC-A', 'price' => '15.99']);
    }
}
