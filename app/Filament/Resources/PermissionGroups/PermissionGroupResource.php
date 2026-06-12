<?php

namespace App\Filament\Resources\PermissionGroups;

use App\Filament\Resources\PermissionGroups\Pages\CreatePermissionGroup;
use App\Filament\Resources\PermissionGroups\Pages\EditPermissionGroup;
use App\Filament\Resources\PermissionGroups\Pages\ListPermissionGroups;
use App\Filament\Resources\PermissionGroups\Schemas\PermissionGroupForm;
use App\Filament\Resources\PermissionGroups\Tables\PermissionGroupsTable;
use App\Models\PermissionGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PermissionGroupResource extends Resource
{
    protected static ?string $model = PermissionGroup::class;

    protected static ?string $navigationLabel = 'Groups';

    protected static string|UnitEnum|null $navigationGroup = 'Users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('permissions.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('permissions.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('permissions.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->can('permissions.manage') ?? false) && ! $record->is_system;
    }

    public static function form(Schema $schema): Schema
    {
        return PermissionGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PermissionGroupsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermissionGroups::route('/'),
            'create' => CreatePermissionGroup::route('/create'),
            'edit' => EditPermissionGroup::route('/{record}/edit'),
        ];
    }
}
