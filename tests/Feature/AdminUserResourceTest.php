<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserResourceTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create();
    }

    public function test_admin_can_list_users(): void
    {
        $admin = $this->adminUser();
        User::factory()->count(3)->create();

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertSuccessful();
    }

    public function test_admin_can_render_create_user_page(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->assertSuccessful();
    }

    public function test_admin_can_create_a_user(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        $newUser = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($newUser->email_verified_at, 'email_verified_at should be set automatically');
    }

    public function test_create_user_requires_unique_email(): void
    {
        $admin = $this->adminUser();
        $existing = User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Another User',
                'email' => 'taken@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_create_user_requires_matching_password_confirmation(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'different',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_admin_can_edit_a_user(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['name' => 'New Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
        ]);
    }

    public function test_admin_can_update_user_password(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm([
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('newpassword', $user->fresh()->password));
    }

    public function test_edit_does_not_change_password_when_left_blank(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['name' => 'Updated Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_admin_can_delete_a_user(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($user);
    }
}
