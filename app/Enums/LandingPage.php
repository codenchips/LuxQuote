<?php

namespace App\Enums;

use App\Filament\Pages\Calendar;
use App\Filament\Pages\Salesforce;
use App\Filament\Pages\Statistics;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\PermissionGroups\PermissionGroupResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\ResourceFiles\ResourceFileResource;
use App\Filament\Resources\SpecialOrderCodes\SpecialOrderCodeResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;

enum LandingPage: string
{
    case Dashboard = 'dashboard';
    case Projects = 'projects';
    case SalesforceProjects = 'salesforce-projects';
    case Visits = 'visits';
    case History = 'history';
    case Specials = 'specials';
    case Products = 'products';
    case Resources = 'resources';
    case Statistics = 'statistics';
    case Users = 'users';
    case Groups = 'groups';
    case Teams = 'teams';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Projects => 'Projects',
            self::SalesforceProjects => 'Projects',
            self::Visits => 'Visits',
            self::History => 'History',
            self::Specials => 'Specials',
            self::Products => 'Products',
            self::Resources => 'Resources',
            self::Statistics => 'Statistics',
            self::Users => 'Users',
            self::Groups => 'Groups',
            self::Teams => 'Teams',
        };
    }

    public function url(): string
    {
        return match ($this) {
            self::Dashboard => rtrim((string) Filament::getUrl(), '/'),
            self::Projects => ProjectResource::getUrl('index'),
            self::SalesforceProjects => Salesforce::getUrl(),
            self::Visits => Calendar::getUrl(),
            self::History => ActivityLogResource::getUrl('index'),
            self::Specials => SpecialOrderCodeResource::getUrl('index'),
            self::Products => ProductResource::getUrl('index'),
            self::Resources => ResourceFileResource::getUrl('index'),
            self::Statistics => Statistics::getUrl(),
            self::Users => UserResource::getUrl('index'),
            self::Groups => PermissionGroupResource::getUrl('index'),
            self::Teams => TeamResource::getUrl('index'),
        };
    }

    public function isAccessibleTo(User $user): bool
    {
        $requiredPermission = match ($this) {
            self::Dashboard => null,
            self::Projects => PermissionKey::ProjectsView,
            self::SalesforceProjects => PermissionKey::SalesforceView,
            self::Visits => PermissionKey::CalendarView,
            self::History => PermissionKey::ActivityLogView,
            self::Specials => PermissionKey::SpecialsManage,
            self::Products => PermissionKey::ProductsView,
            self::Resources => PermissionKey::ResourcesView,
            self::Statistics => PermissionKey::StatisticsView,
            self::Users => PermissionKey::UsersView,
            self::Groups => PermissionKey::PermissionsManage,
            self::Teams => PermissionKey::TeamsManage,
        };

        return $requiredPermission === null || $user->can($requiredPermission->value);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        return [
            'General' => [
                self::Dashboard->value => self::Dashboard->label(),
                self::Projects->value => self::Projects->label(),
            ],
            'Salesforce' => [
                self::SalesforceProjects->value => self::SalesforceProjects->label(),
                self::Visits->value => self::Visits->label(),
            ],
            'Admin' => [
                self::History->value => self::History->label(),
                self::Specials->value => self::Specials->label(),
                self::Products->value => self::Products->label(),
                self::Resources->value => self::Resources->label(),
                self::Statistics->value => self::Statistics->label(),
            ],
            'Users' => [
                self::Users->value => self::Users->label(),
                self::Groups->value => self::Groups->label(),
                self::Teams->value => self::Teams->label(),
            ],
        ];
    }
}
