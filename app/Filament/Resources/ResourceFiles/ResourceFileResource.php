<?php

namespace App\Filament\Resources\ResourceFiles;

use App\Enums\PermissionKey;
use App\Filament\Resources\ResourceFiles\Pages\CreateResourceFile;
use App\Filament\Resources\ResourceFiles\Pages\ListResourceFiles;
use App\Filament\Resources\ResourceFiles\Schemas\ResourceFileForm;
use App\Filament\Resources\ResourceFiles\Tables\ResourceFilesTable;
use App\Models\ResourceFile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ResourceFileResource extends Resource
{
    protected static ?string $model = ResourceFile::class;

    protected static ?string $navigationLabel = 'Resources';

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'resources';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(PermissionKey::ResourcesView->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(PermissionKey::ResourcesCreate->value) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can(PermissionKey::ResourcesUpdate->value) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can(PermissionKey::ResourcesDelete->value) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ResourceFileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResourceFilesTable::configure($table);
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
            'index' => ListResourceFiles::route('/'),
            'create' => CreateResourceFile::route('/create'),
        ];
    }
}
