<?php

namespace Tests\Feature;

use App\Enums\LandingPage;
use App\Filament\Pages\Calendar;
use App\Http\Responses\LoginResponse;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_application_uses_custom_filament_login_response(): void
    {
        $this->assertInstanceOf(LoginResponse::class, app(LoginResponseContract::class));
    }

    public function test_every_top_level_page_is_available_as_a_group_landing_page(): void
    {
        $optionValues = collect(LandingPage::groupedOptions())
            ->flatMap(fn (array $options): array => array_keys($options))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            array_column(LandingPage::cases(), 'value'),
            $optionValues,
        );
    }

    public function test_group_configured_for_projects_lands_on_projects(): void
    {
        $this->actingAs($this->userWithPermissions(['projects.view'], LandingPage::Projects));

        $response = app(LoginResponseContract::class)->toResponse(request());

        $this->assertSame(LandingPage::Projects->url(), $response->getTargetUrl());
    }

    public function test_group_configured_for_an_authorized_top_level_page_lands_there(): void
    {
        $this->actingAs($this->userWithPermissions(['calendar.view'], LandingPage::Visits));

        $response = app(LoginResponseContract::class)->toResponse(request());

        $this->assertSame(Calendar::getUrl(), $response->getTargetUrl());
    }

    public function test_group_defaults_to_dashboard(): void
    {
        $this->actingAs($this->userWithPermissions(['projects.view']));

        $response = app(LoginResponseContract::class)->toResponse(request());

        $this->assertSame(rtrim((string) Filament::getUrl(), '/'), $response->getTargetUrl());
    }

    public function test_inaccessible_group_landing_page_falls_back_to_dashboard(): void
    {
        $this->actingAs($this->userWithPermissions(['projects.view'], LandingPage::Products));

        $response = app(LoginResponseContract::class)->toResponse(request());

        $this->assertSame(LandingPage::Dashboard->url(), $response->getTargetUrl());
    }

    public function test_intended_destination_is_preserved_for_project_only_user(): void
    {
        $this->actingAs($this->userWithPermissions(['projects.view'], LandingPage::Projects));
        $intendedUrl = url('/projects/42');
        session()->put('url.intended', $intendedUrl);

        $response = app(LoginResponseContract::class)->toResponse(request());

        $this->assertSame($intendedUrl, $response->getTargetUrl());
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys, LandingPage $landingPage = LandingPage::Dashboard): User
    {
        $group = PermissionGroup::create([
            'name' => 'Limited navigation',
            'slug' => 'limited-navigation',
            'default_landing_page' => $landingPage,
            'is_system' => false,
        ]);

        $group->permissions()->attach(
            Permission::query()->whereIn('key', $permissionKeys)->pluck('id'),
        );

        return User::factory()->create([
            'permission_group_id' => $group->id,
        ]);
    }
}
