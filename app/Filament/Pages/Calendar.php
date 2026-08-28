<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Calendar extends Page
{
    protected static ?string $navigationLabel = 'Visits';

    protected static string|UnitEnum|null $navigationGroup = 'Salesforce';

    protected static ?string $title = 'Calendar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.calendar';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('calendar.view') ?? false;
    }
}
