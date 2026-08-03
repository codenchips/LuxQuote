<?php

namespace App\Filament\Resources\SpecialOrderCodes;

use App\Filament\Resources\SpecialOrderCodes\Pages\CreateSpecialOrderCode;
use App\Filament\Resources\SpecialOrderCodes\Pages\EditSpecialOrderCode;
use App\Filament\Resources\SpecialOrderCodes\Pages\ListSpecialOrderCodes;
use App\Filament\Resources\SpecialOrderCodes\Schemas\SpecialOrderCodeForm;
use App\Filament\Resources\SpecialOrderCodes\Tables\SpecialOrderCodesTable;
use App\Models\SpecialOrderCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SpecialOrderCodeResource extends Resource
{
    protected static ?string $model = SpecialOrderCode::class;

    protected static ?string $navigationLabel = 'Specials';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('specials.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('specials.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('specials.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('specials.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return SpecialOrderCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SpecialOrderCodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpecialOrderCodes::route('/'),
            'create' => CreateSpecialOrderCode::route('/create'),
            'edit' => EditSpecialOrderCode::route('/{record}/edit'),
        ];
    }
}
