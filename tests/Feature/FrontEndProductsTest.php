<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FrontEndProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_products_page(): void
    {
        $response = $this->get('/products');

        $response->assertRedirect('/login');
    }

    public function test_user_role_can_view_products(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(ListProducts::class)
            ->assertSuccessful();
    }

    public function test_products_page_shows_product_data(): void
    {
        $user = User::factory()->create();
        Product::factory()->create([
            'product_name' => 'Super Bright LED',
            'description' => 'Super Bright LED visual description',
            'sku' => 'SB-LED-001',
            'site' => 'xcite',
            'type_name' => 'Downlights',
        ]);

        $this->actingAs($user);

        Livewire::test(ListProducts::class)
            ->assertSee('Super Bright LED visual description')
            ->assertSee('SB-LED-001')
            ->assertSee('xcite')
            ->assertSee('Downlights');
    }

    public function test_fetch_products_button_not_visible_for_user_role(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(ListProducts::class)
            ->assertDontSee('Fetch Products');
    }

    public function test_fetch_products_button_visible_for_admin_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(ListProducts::class)
            ->assertSee('Fetch Products');
    }
}
