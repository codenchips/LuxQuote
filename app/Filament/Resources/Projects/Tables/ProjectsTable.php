<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                        ProjectStatus::Archived => 'gray',
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
            ->actions([
                Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->iconButton()
                    ->tooltip('Copy project')
                    ->action(function (Project $record): void {
                        $attributes = $record->only([
                            'user_id', 'name', 'customer_name', 'contractor', 'site_location',
                            'owner_email', 'created_by_email', 'department', 'date', 'revision',
                            'visibility', 'status', 'branch_name', 'cover_percentage',
                            'quote_notes', 'internal_notes', 'general_notes',
                        ]);

                        $attributes['name'] = $record->name.' - Copy';
                        $attributes['reference_number'] = null;

                        $copy = Project::withoutEvents(fn (): Project => Project::create($attributes));

                        foreach ($record->areas()->with('lines')->get() as $area) {
                            $newArea = $copy->areas()->create([
                                'name' => $area->name,
                                'sort_order' => $area->sort_order,
                            ]);

                            foreach ($area->lines as $line) {
                                $newArea->lines()->create($line->only([
                                    'product_id', 'code', 'ref', 'description', 'qty',
                                    'type', 'unit_price', 'notes', 'status', 'sort_order',
                                ]));
                            }
                        }
                    }),

                EditAction::make()
                    ->slideOver()
                    ->icon('heroicon-o-pencil')
                    ->iconButton()
                    ->tooltip('Edit project'),

                ActionGroup::make([
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('warning')
                        ->action(fn (Project $record) => $record->update(['status' => ProjectStatus::Archived])),

                    Action::make('delete')
                        ->label('Delete permanently')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete project permanently?')
                        ->modalDescription('This will permanently delete the project and all its areas and lines. This cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete permanently')
                        ->action(fn (Project $record) => $record->delete()),
                ])
                    ->icon('heroicon-o-trash')
                    ->color('gray')
                    ->tooltip('Delete / Archive'),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', ProjectStatus::Archived->value));
    }
}
