<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\User;
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

        request()->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36');
        request()->headers->set('Sec-CH-UA-Platform', '"Windows"');
        request()->headers->set('Accept-Language', 'en-GB,en-US;q=0.9,en;q=0.8');

        auth()->login($user);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => 'login@example.com',
            'project_name_snapshot' => null,
        ]);

        $log = ActivityLog::where('action_type', 'user.login')->sole();

        $this->assertSame('Chrome', $log->payload['login_context']['browser']);
        $this->assertSame('Windows', $log->payload['login_context']['platform']);
        $this->assertMatchesRegularExpression('/^Chrome on Windows · #[A-F0-9]{6}$/', $log->payload['login_context']['display']);
    }

    public function test_activity_logs_table_shows_login_context(): void
    {
        $user = User::factory()->admin()->create([
            'name' => 'Dean',
        ]);

        ActivityLog::create([
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $user->email,
            'project_name_snapshot' => null,
            'payload' => [
                'login_context' => [
                    'display' => 'Chrome on Windows · #ABC123',
                ],
            ],
        ]);

        $this->actingAs($user);

        Livewire::test(ListActivityLogs::class)
            ->assertSuccessful()
            ->assertSee('Logged in')
            ->assertSee('Chrome on Windows · #ABC123');
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
