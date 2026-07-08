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
        $admin = User::factory()->admin()->create();
        Product::factory()->count(3)->create();

        $this->actingAs($admin);

        Livewire::test(ListProducts::class)
            ->assertSuccessful();
    }

    public function test_products_table_shows_required_columns(): void
    {
        $admin = User::factory()->admin()->create();
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
            ->assertSee($product->product_name)
            ->assertSee($product->sku)
            ->assertSee('42.50')
            ->assertSee($product->site)
            ->assertSee($product->type_name)
            ->assertDontSee($product->description);
    }

    public function test_fetch_products_action_imports_and_shows_notification(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            '*' => Http::response([
                'columns' => ['id', 'product', 'type', 'sku', 'description', 'cost', 'site'],
                'data' => [
                    ['id' => '1', 'site' => 'xcite', 'product' => 'Lamp A', 'sku' => 'XC-A',
                        'cost' => '15.99', 'description' => 'Lamp A visual description', 'type' => 'Downlights'],
                ],
            ], 200),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListProducts::class)
            ->callAction('fetchProducts')
            ->assertHasNoActionErrors();

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', [
            'sku' => 'XC-A',
            'product_name' => 'Lamp A',
            'description' => 'Lamp A visual description',
            'price' => '15.99',
        ]);
    }
}
