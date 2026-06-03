<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectSubNav;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ValidationProject extends ViewRecord
{
    use HasProjectSubNav;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.validation-project';

    protected static ?string $navigationLabel = 'Validation';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }
}
