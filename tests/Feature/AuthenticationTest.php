<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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

    public function test_user_login_is_recorded_in_activity_logs(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
        ]);

        auth()->login($user);

        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => 'login@example.com',
            'project_name_snapshot' => null,
        ]);

        $this->assertSame(1, ActivityLog::where('action_type', 'user.login')->count());
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
