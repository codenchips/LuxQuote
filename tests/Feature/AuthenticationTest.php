<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_is_accessible_to_guests(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_panel_root_is_inaccessible_to_guests(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertSuccessful();
    }

    public function test_authenticated_user_is_redirected_from_login(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->assertAuthenticated();

        auth()->logout();

        $this->assertGuest();
    }
}
