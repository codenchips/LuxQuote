<?php

namespace Tests\Feature;

use App\Filament\Resources\PermissionGroups\Pages\CreatePermissionGroup;
use App\Filament\Resources\PermissionGroups\Pages\ListPermissionGroups;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPermissionResourceTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_admin_can_list_groups_and_permissions(): void
    {
        $this->actingAs($this->adminUser());

        Livewire::test(ListPermissionGroups::class)
            ->assertSuccessful();

        Livewire::test(ListPermissions::class)
            ->assertSuccessful();
    }

    public function test_admin_can_create_permission_group_with_permissions(): void
    {
        $this->actingAs($this->adminUser());

        $permissions = Permission::whereIn('key', [
            'projects.view',
            'projects.create',
        ])->pluck('id')->all();

        Livewire::test(CreatePermissionGroup::class)
            ->fillForm([
                'name' => 'Estimators',
                'slug' => 'estimators',
                'description' => 'Estimator access',
                'permissions' => $permissions,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = PermissionGroup::where('slug', 'estimators')->firstOrFail();
        $permissionKeys = $group->permissions()->pluck('key')->sort()->values()->all();

        $this->assertSame(['projects.create', 'projects.view'], $permissionKeys);
    }

    public function test_admin_can_assign_user_to_group(): void
    {
        $this->actingAs($this->adminUser());

        $salesGroup = PermissionGroup::where('slug', 'sales')->firstOrFail();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Sales User',
                'email' => 'sales-user@example.com',
                'permission_group_id' => $salesGroup->id,
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $createdUser = User::where('email', 'sales-user@example.com')->firstOrFail();

        $this->assertTrue($createdUser->can('pricing.view'));
        $this->assertSame($salesGroup->id, $createdUser->permission_group_id);
    }

    public function test_permissions_catalog_is_read_only(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin);

        $permission = Permission::where('key', 'projects.view')->firstOrFail();

        $this->assertFalse(PermissionResource::canCreate());
        $this->assertFalse(PermissionResource::canEdit($permission));
        $this->assertFalse(PermissionResource::canDelete($permission));
    }

    public function test_permissions_catalog_is_hidden_from_navigation(): void
    {
        $this->assertFalse(PermissionResource::shouldRegisterNavigation());
    }
}
