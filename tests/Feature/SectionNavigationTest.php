<?php

namespace Tests\Feature;

use App\Enums\LandingPage;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\Salesforce;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SpecialOrderCodes\SpecialOrderCodeResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\SectionNavigation;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SectionNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
    }

    public function test_admin_sidebar_contains_exactly_the_seven_sections(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $items = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems());
        $this->assertSame(['Dashboard', 'Projects', 'Salesforce', 'Catalogue', 'Resources', 'Reporting', 'Administration'], $items->map(fn ($item) => $item->getLabel())->all());
        $menus = SectionNavigation::popupMenus();
        $this->assertSame(['salesforce', 'catalogue', 'reporting', 'administration'], array_keys($menus));
        $this->assertSame(['Customers', 'Projects', 'Visits'], array_column($menus['salesforce']['links'], 'label'));
        $this->assertSame(['Users', 'Permission Groups', 'Teams', 'Tools'], array_column($menus['administration']['links'], 'label'));
        foreach ($items->filter(fn ($item) => isset($menus[$item->getExtraAttributes()['data-navigation-section'] ?? ''])) as $item) {
            $this->assertArrayHasKey('x-on:mouseenter', $item->getExtraAttributes());
            $this->assertArrayHasKey('x-on:keydown.arrow-right.prevent.stop', $item->getExtraAttributes());
        }
    }

    public function test_visits_only_user_gets_salesforce_section_linked_directly_to_visits(): void
    {
        $this->signInWithPermissions(['calendar.view']);
        $items = SectionNavigation::sidebarItems();
        $this->assertSame(['Dashboard', 'Salesforce'], array_map(fn ($item) => $item->getLabel(), $items));
        $this->assertSame(Calendar::getUrl(), $items[1]->getUrl());
        $this->assertSame(['Visits'], array_column(Salesforce::subNavigationLinks(), 'label'));
        $this->assertSame(['salesforce'], array_keys(SectionNavigation::popupMenus()));
        $this->get(Salesforce::getUrl())->assertForbidden();
    }

    public function test_sections_open_first_permitted_subpage_and_hide_unauthorized_subpages(): void
    {
        $this->signInWithPermissions(['specials.manage', 'activity-log.view', 'teams.manage']);
        $items = collect(SectionNavigation::sidebarItems())->keyBy(fn ($item) => $item->getLabel());
        $this->assertSame(['Dashboard', 'Catalogue', 'Reporting', 'Administration'], $items->keys()->all());
        $this->assertSame(SpecialOrderCodeResource::getUrl(), $items['Catalogue']->getUrl());
        $this->assertSame(ActivityLogResource::getUrl(), $items['Reporting']->getUrl());
        $this->assertSame(TeamResource::getUrl(), $items['Administration']->getUrl());
        $this->assertSame(['Teams'], array_column(SectionNavigation::links('administration'), 'label'));
        $this->assertSame(['Specials'], array_column(SectionNavigation::links('catalogue'), 'label'));
        $this->get(ProductResource::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl())->assertForbidden();
    }

    public function test_administration_subnavigation_and_active_section_render_on_edit_pages(): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        $response = $this->get(UserResource::getUrl('edit', ['record' => $actor]))->assertOk();
        $response->assertSee('Permission Groups')->assertSee('Teams')->assertSee('luxQuoteSectionMenu', false);
        $this->assertSame('administration', SectionNavigation::currentSection());
        $links = SectionNavigation::forCurrentRequest();
        $this->assertSame(['Users', 'Permission Groups', 'Teams', 'Tools'], array_column($links, 'label'));
        $this->assertTrue($links[0]['active']);
        $this->assertFalse($links[1]['active']);
        $this->assertTrue(collect(SectionNavigation::sidebarItems())->first(fn ($item) => $item->getLabel() === 'Administration')->isActive());
    }

    public function test_popup_payload_does_not_include_unavailable_destinations(): void
    {
        $this->signInWithPermissions(['products.view']);
        $response = $this->get(ProductResource::getUrl())->assertOk();
        $response->assertSee('data-navigation-section="catalogue"', false);
        $menus = SectionNavigation::popupMenus();
        $this->assertSame(['catalogue'], array_keys($menus));
        $this->assertSame(['Products'], array_column($menus['catalogue']['links'], 'label'));
        $this->assertSame([], SectionNavigation::links('administration'));
    }

    public function test_saved_landing_pages_remain_available_under_new_groups(): void
    {
        $this->assertSame(['General', 'Salesforce', 'Catalogue', 'Resources', 'Reporting', 'Administration'], array_keys(LandingPage::groupedOptions()));
        $this->assertEqualsCanonicalizing(array_column(LandingPage::cases(), 'value'), collect(LandingPage::groupedOptions())->flatMap(fn ($options) => array_keys($options))->all());
        $this->assertSame('Permission Groups', LandingPage::Groups->label());
    }

    /** @param array<string> $permissions */
    private function signInWithPermissions(array $permissions): void
    {
        $group = PermissionGroup::create(['name' => 'Navigation test', 'slug' => 'navigation-test']);
        $group->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));
        $this->actingAs(User::factory()->create(['permission_group_id' => $group->id]));
    }
}
