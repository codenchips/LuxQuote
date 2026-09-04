<?php

namespace Tests\Feature;

use App\Filament\Pages\Calendar;
use App\Filament\Pages\Salesforce;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\PermissionGroups\PermissionGroupResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SpecialOrderCodes\SpecialOrderCodeResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Facades\Filament;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    public function test_admin_panel_does_not_show_global_search(): void
    {
        $this->assertNull(Filament::getPanel('admin')->getGlobalSearchProvider());
    }

    public function test_admin_navigation_groups_are_registered_in_the_requested_order(): void
    {
        $this->assertSame(
            ['Salesforce', 'Admin', 'Users'],
            Filament::getPanel('admin')->getNavigationGroups(),
        );
    }

    public function test_salesforce_navigation_contains_projects_then_visits(): void
    {
        $this->assertSame('Salesforce', Salesforce::getNavigationGroup());
        $this->assertSame('Projects', Salesforce::getNavigationLabel());
        $this->assertSame(1, Salesforce::getNavigationSort());

        $this->assertSame('Salesforce', Calendar::getNavigationGroup());
        $this->assertSame('Visits', Calendar::getNavigationLabel());
        $this->assertSame(2, Calendar::getNavigationSort());
    }

    public function test_admin_navigation_contains_history_specials_then_products(): void
    {
        $this->assertSame('Admin', ActivityLogResource::getNavigationGroup());
        $this->assertSame('History', ActivityLogResource::getNavigationLabel());
        $this->assertSame(1, ActivityLogResource::getNavigationSort());

        $this->assertSame('Admin', SpecialOrderCodeResource::getNavigationGroup());
        $this->assertSame('Specials', SpecialOrderCodeResource::getNavigationLabel());
        $this->assertSame(2, SpecialOrderCodeResource::getNavigationSort());

        $this->assertSame('Admin', ProductResource::getNavigationGroup());
        $this->assertSame('Products', ProductResource::getNavigationLabel());
        $this->assertSame(3, ProductResource::getNavigationSort());
    }

    public function test_users_navigation_places_teams_below_groups(): void
    {
        $this->assertSame('Users', UserResource::getNavigationGroup());
        $this->assertSame(3, UserResource::getNavigationSort());

        $this->assertSame('Users', PermissionGroupResource::getNavigationGroup());
        $this->assertSame(4, PermissionGroupResource::getNavigationSort());

        $this->assertSame('Users', TeamResource::getNavigationGroup());
        $this->assertSame('Teams', TeamResource::getNavigationLabel());
        $this->assertSame(5, TeamResource::getNavigationSort());
    }
}
