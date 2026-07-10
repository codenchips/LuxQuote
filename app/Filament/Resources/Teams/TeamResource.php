<?php

namespace App\Filament\Resources\Teams;

use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Schemas\TeamForm;
use App\Filament\Resources\Teams\Tables\TeamsTable;
use App\Models\Team;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static ?string $navigationLabel = 'Teams';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 99;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('teams.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('teams.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('teams.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('teams.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return TeamForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamsTable::configure($table);
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
            'index' => ListTeams::route('/'),
            'create' => CreateTeam::route('/create'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];
    }
}
