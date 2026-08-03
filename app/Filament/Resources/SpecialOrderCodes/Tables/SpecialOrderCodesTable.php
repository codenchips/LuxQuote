<?php

namespace App\Filament\Resources\SpecialOrderCodes\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SpecialOrderCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->description("Changing a special code's price or description will not apply to projects retrospectively.")
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(90),
                TextColumn::make('price')
                    ->label('Price')
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) ($state ?? 0), 2, '.', ''))
                    ->sortable(),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->formatStateUsing(fn (mixed $state): string => (string) (int) ($state ?? 0))
                    ->sortable(),
                TextColumn::make('requires_approval')
                    ->label('Requires Approval')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => (bool) $state ? 'Yes' : 'No')
                    ->color(fn (mixed $state): string => (bool) $state ? 'warning' : 'gray'),
                TextColumn::make('show_on_schedules')
                    ->label('Show on Schedules')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => (bool) $state ? 'Yes' : 'No')
                    ->color(fn (mixed $state): string => (bool) $state ? 'success' : 'gray'),
                TextColumn::make('show_on_quotes')
                    ->label('Show on Quotes')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => (bool) $state ? 'Yes' : 'No')
                    ->color(fn (mixed $state): string => (bool) $state ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M d Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalHeading('Delete special order code')
                    ->modalDescription('Delete this special order code? Existing project lines will keep their stored values, but future matching will no longer use this special rule.'),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
