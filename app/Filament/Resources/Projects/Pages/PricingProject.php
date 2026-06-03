<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class PricingProject extends ViewRecord
{
    use HasProjectSubNav;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.pricing-project';

    protected static ?string $navigationLabel = 'Pricing';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyPound;

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }
}
