<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_number')
                    ->label('Reference')
                    ->placeholder('–')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Project Name')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => ProjectResource::getUrl('view', ['record' => $record])),

                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('owner_email')
                    ->label('Owner')
                    ->placeholder('–')
                    ->searchable(),

                TextColumn::make('department')
                    ->label('Dept.')
                    ->placeholder('–')
                    ->sortable(),

                TextColumn::make('date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('revision')
                    ->label('Rev')
                    ->formatStateUsing(fn (int $state): string => 'R'.$state)
                    ->badge()
                    ->color('gray'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ProjectStatus $state): string => $state->label())
                    ->color(fn (ProjectStatus $state): string => match ($state) {
                        ProjectStatus::Draft => 'gray',
                        ProjectStatus::InProgress => 'info',
                        ProjectStatus::Complete => 'success',
                        ProjectStatus::Cancelled => 'danger',
                    })
                    ->sortable(),

                TextColumn::make('visibility')
                    ->label('Visibility')
                    ->badge()
                    ->formatStateUsing(fn (ProjectVisibility $state): string => $state->label())
                    ->icon(fn (ProjectVisibility $state): string => match ($state) {
                        ProjectVisibility::Open => 'heroicon-o-globe-alt',
                        ProjectVisibility::Private => 'heroicon-o-lock-closed',
                    })
                    ->color(fn (ProjectVisibility $state): string => match ($state) {
                        ProjectVisibility::Open => 'success',
                        ProjectVisibility::Private => 'warning',
                    })
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
