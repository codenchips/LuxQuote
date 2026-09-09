<?php

namespace Tests\Feature;

use App\Enums\LandingPage;
use App\Enums\UserRole;
use App\Filament\Resources\PermissionGroups\Pages\CreatePermissionGroup;
use App\Filament\Resources\PermissionGroups\Pages\EditPermissionGroup;
use App\Filament\Resources\PermissionGroups\Pages\ListPermissionGroups;
use App\Filament\Resources\PermissionGroups\Schemas\PermissionGroupForm;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

        $projectPermissions = Permission::whereIn('key', [
            'projects.view',
            'projects.create',
        ])->pluck('id')->all();
        $resourcePermission = Permission::where('key', 'resources.view')->value('id');

        Livewire::test(CreatePermissionGroup::class)
            ->fillForm([
                'name' => 'Estimators',
                'slug' => 'estimators',
                'description' => 'Estimator access',
                'default_landing_page' => LandingPage::Projects->value,
                'project_permissions' => $projectPermissions,
                'resources_permissions' => [$resourcePermission],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = PermissionGroup::where('slug', 'estimators')->firstOrFail();
        $permissionKeys = $group->permissions()->pluck('key')->sort()->values()->all();

        $this->assertSame(['projects.create', 'projects.view', 'resources.view'], $permissionKeys);
        $this->assertSame(LandingPage::Projects, $group->default_landing_page);
    }

    public function test_permission_settings_are_grouped_once_by_area(): void
    {
        $this->actingAs($this->adminUser());

        $component = Livewire::test(CreatePermissionGroup::class);

        foreach (array_keys(PermissionGroupForm::permissionAreas()) as $area) {
            $component->assertFormFieldExists(Str::snake($area).'_permissions');
        }

        $mappedCategories = collect(PermissionGroupForm::permissionAreas())->flatten();
        $databaseCategories = Permission::query()->distinct()->pluck('category');

        $this->assertCount($mappedCategories->unique()->count(), $mappedCategories);
        $this->assertEqualsCanonicalizing($databaseCategories->all(), $mappedCategories->all());
    }

    public function test_permission_search_filters_all_areas_from_one_field(): void
    {
        $this->actingAs($this->adminUser());

        $component = Livewire::test(CreatePermissionGroup::class)
            ->assertFormFieldExists('permission_search');

        foreach (array_keys(PermissionGroupForm::permissionAreas()) as $area) {
            $component->assertFormFieldExists(
                Str::snake($area).'_permissions',
                checkFieldUsing: fn (CheckboxList $field): bool => $field->isSearchable()
                    && $field->getExtraAlpineAttributeBag()->get('x-on:permission-search.window') === 'search = $event.detail',
            );
        }
    }

    public function test_saving_with_an_active_permission_search_preserves_hidden_assignments(): void
    {
        $admin = $this->adminUser();
        $group = PermissionGroup::where('slug', 'user')->firstOrFail();
        $permissions = Permission::whereIn('key', [
            'projects.view',
            'resources.delete',
        ])->get();

        $group->permissions()->sync($permissions->modelKeys());
        $this->actingAs($admin);

        Livewire::test(EditPermissionGroup::class, ['record' => $group->getRouteKey()])
            ->fillForm(['permission_search' => 'resources.delete'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            ['projects.view', 'resources.delete'],
            $group->fresh()->permissions()->pluck('key')->all(),
        );
    }

    public function test_admin_can_update_a_group_landing_page(): void
    {
        $admin = $this->adminUser();
        $group = PermissionGroup::where('slug', 'user')->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(EditPermissionGroup::class, ['record' => $group->getRouteKey()])
            ->fillForm([
                'default_landing_page' => LandingPage::Visits->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(LandingPage::Visits, $group->fresh()->default_landing_page);
    }

    public function test_admin_group_permissions_are_locked_and_repaired_when_saved(): void
    {
        $admin = $this->adminUser();
        $adminGroup = PermissionGroup::where('slug', 'admin')->firstOrFail();
        $permission = Permission::where('key', 'resources.delete')->firstOrFail();
        $adminGroup->permissions()->detach($permission);

        $this->actingAs($admin);

        $component = Livewire::test(EditPermissionGroup::class, ['record' => $adminGroup->getRouteKey()]);

        foreach (array_keys(PermissionGroupForm::permissionAreas()) as $area) {
            $component->assertFormFieldDisabled(Str::snake($area).'_permissions');
        }

        $component
            ->fillForm(['resources_permissions' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            Permission::query()->count(),
            $adminGroup->fresh()->permissions()->count(),
        );
    }

    public function test_new_permissions_are_automatically_assigned_to_the_admin_group(): void
    {
        $adminGroup = PermissionGroup::where('slug', 'admin')->firstOrFail();

        $permission = Permission::create([
            'key' => 'test.future-permission',
            'name' => 'Future permission',
            'category' => 'Users & Admin',
            'description' => 'Test permission.',
        ]);

        $this->assertTrue($adminGroup->permissions()->whereKey($permission->id)->exists());
    }

    public function test_non_admin_group_member_cannot_promote_or_demote_admin_members_even_with_management_permissions(): void
    {
        $adminGroup = PermissionGroup::where('slug', 'admin')->firstOrFail();
        $userGroup = PermissionGroup::where('slug', 'user')->firstOrFail();
        $managerGroup = PermissionGroup::where('slug', 'manager')->firstOrFail();
        $managerGroup->permissions()->syncWithoutDetaching(
            Permission::whereIn('key', ['users.create', 'users.update', 'users.delete', 'permissions.manage'])->pluck('id'),
        );
        $manager = User::factory()->create(['permission_group_id' => $managerGroup->id]);
        $ordinaryUser = User::factory()->create(['permission_group_id' => $userGroup->id]);
        $admin = $this->adminUser();

        $this->actingAs($manager);

        try {
            $ordinaryUser->update(['permission_group_id' => $adminGroup->id]);
            $this->fail('A non-Admin-group member promoted a user to Admin.');
        } catch (AuthorizationException) {
            $this->assertSame($userGroup->id, $ordinaryUser->fresh()->permission_group_id);
        }

        try {
            $admin->update(['permission_group_id' => $userGroup->id]);
            $this->fail('A non-Admin-group member removed an Admin.');
        } catch (AuthorizationException) {
            $this->assertSame($adminGroup->id, $admin->fresh()->permission_group_id);
        }
    }

    public function test_admin_group_is_hidden_or_locked_in_user_forms_for_non_admin_group_members(): void
    {
        $adminGroup = PermissionGroup::where('slug', 'admin')->firstOrFail();
        $managerGroup = PermissionGroup::where('slug', 'manager')->firstOrFail();
        $managerGroup->permissions()->syncWithoutDetaching(
            Permission::whereIn('key', ['users.view', 'users.create', 'users.update'])->pluck('id'),
        );
        $manager = User::factory()->create(['permission_group_id' => $managerGroup->id]);
        $admin = $this->adminUser();

        $this->actingAs($manager);

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists(
                'permission_group_id',
                checkFieldUsing: fn (Select $field): bool => ! array_key_exists($adminGroup->id, $field->getOptions()),
            );

        Livewire::test(EditUser::class, ['record' => $admin->id])
            ->assertFormFieldDisabled('permission_group_id');
    }

    public function test_legacy_admin_role_does_not_authorize_admin_group_membership_changes(): void
    {
        $adminGroup = PermissionGroup::where('slug', 'admin')->firstOrFail();
        $userGroup = PermissionGroup::where('slug', 'user')->firstOrFail();
        $legacyAdmin = User::factory()->create([
            'role' => UserRole::Admin,
            'permission_group_id' => $userGroup->id,
        ]);
        $ordinaryUser = User::factory()->create(['permission_group_id' => $userGroup->id]);

        $this->actingAs($legacyAdmin);
        $this->expectException(AuthorizationException::class);

        $ordinaryUser->update(['permission_group_id' => $adminGroup->id]);
    }

    public function test_admin_group_member_can_promote_and_demote_another_user(): void
    {
        $actor = $this->adminUser();
        $adminGroup = PermissionGroup::where('slug', 'admin')->firstOrFail();
        $userGroup = PermissionGroup::where('slug', 'user')->firstOrFail();
        $user = User::factory()->create(['permission_group_id' => $userGroup->id]);

        $this->actingAs($actor);

        $user->update(['permission_group_id' => $adminGroup->id]);
        $this->assertSame($adminGroup->id, $user->fresh()->permission_group_id);

        $user->update(['permission_group_id' => $userGroup->id]);
        $this->assertSame($userGroup->id, $user->fresh()->permission_group_id);
    }

    public function test_admin_group_member_can_assign_and_remove_admin_membership_through_user_forms(): void
    {
        $actor = $this->adminUser();
        $adminGroup = PermissionGroup::where('slug', 'admin')->firstOrFail();
        $userGroup = PermissionGroup::where('slug', 'user')->firstOrFail();

        $this->actingAs($actor);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Second Admin',
                'email' => 'second-admin@example.com',
                'permission_group_id' => $adminGroup->id,
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $secondAdmin = User::where('email', 'second-admin@example.com')->firstOrFail();
        $this->assertSame($adminGroup->id, $secondAdmin->permission_group_id);

        Livewire::test(EditUser::class, ['record' => $secondAdmin->id])
            ->fillForm(['permission_group_id' => $userGroup->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($userGroup->id, $secondAdmin->fresh()->permission_group_id);
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
