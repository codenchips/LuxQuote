<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('permissionGroup.name')
                    ->label('Group')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'Admin' ? 'danger' : 'primary')
                    ->placeholder('No group')
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label('Last login')
                    ->dateTime('M d Y H:i')
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('projects_count')
                    ->label('Num Projects')
                    ->counts('projects')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('M d Y H:i')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime('M d Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
