<?php

namespace App\Filament\Resources\ActivityLogArchives;

use App\Filament\Resources\ActivityLogArchives\Pages\ListActivityLogArchives;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use App\Models\ActivityLogArchive;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ActivityLogArchiveResource extends Resource
{
    protected static ?string $model = ActivityLogArchive::class;

    protected static ?string $navigationLabel = 'Archived Logs';

    protected static ?string $modelLabel = 'Archived Log';

    protected static ?string $pluralModelLabel = 'Archived Logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('activity-log.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table, archived: true);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogArchives::route('/'),
        ];
    }
}
