<?php

namespace Tests\Feature;

use App\Enums\PermissionKey;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\TeamResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTeamResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_team_with_members(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['name' => 'Team Member']);

        $this->actingAs($admin);

        Livewire::test(CreateTeam::class)
            ->fillForm([
                'name' => 'Specification Team',
                'slug' => 'specification-team',
                'description' => 'Specification projects',
                'users' => [$member->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $team = Team::where('slug', 'specification-team')->firstOrFail();

        $this->assertSame('Specification Team', $team->name);
        $this->assertTrue($team->users()->whereKey($member->id)->exists());
    }

    public function test_teams_resource_is_permission_gated(): void
    {
        $admin = User::factory()->admin()->create();
        $standardUser = User::factory()->create();

        $this->actingAs($admin);
        $this->assertTrue(TeamResource::canViewAny());
        $this->assertTrue($admin->can(PermissionKey::TeamsManage->value));

        $this->actingAs($standardUser);
        $this->assertFalse(TeamResource::canViewAny());
        $this->assertFalse($standardUser->can(PermissionKey::TeamsManage->value));
    }

    public function test_profile_shows_current_users_teams(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Lighting Designers',
            'slug' => 'lighting-designers',
        ]);

        $team->users()->attach($user);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertSuccessful()
            ->assertSee('Project list view')
            ->assertSee('All available projects')
            ->assertSee('Lighting Designers')
            ->assertSee('User Group')
            ->assertSee($user->permissionGroup->name)
            ->assertDontSee('Role');
    }

    public function test_profile_can_store_project_list_view_team_preference(): void
    {
        $user = User::factory()->create([
            'name' => 'Project Viewer',
            'area_code' => 'PX',
        ]);
        $preferredTeam = Team::create([
            'name' => 'Lighting Designers',
            'slug' => 'lighting-designers',
        ]);
        $otherTeam = Team::create([
            'name' => 'Sales Team',
            'slug' => 'sales-team',
        ]);

        $preferredTeam->users()->attach($user);
        $otherTeam->users()->attach($user);

        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'name' => 'Project Viewer',
                'area_code' => 'PX',
                'project_list_team_id' => (string) $preferredTeam->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($preferredTeam->id, $user->fresh()->project_list_team_id);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'name' => 'Project Viewer',
                'area_code' => 'PX',
                'project_list_team_id' => 'all',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($user->fresh()->project_list_team_id);
    }

    public function test_admin_can_list_teams(): void
    {
        $admin = User::factory()->admin()->create();
        Team::create([
            'name' => 'Sales North',
            'slug' => 'sales-north',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListTeams::class)
            ->assertSuccessful()
            ->assertSee('Sales North');
    }
}
