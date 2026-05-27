<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectRevision;
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

                TextColumn::make('last_edited_at')
                    ->label('Last Edited')
                    ->since()
                    ->sortable()
                    ->placeholder('Never')
                    ->tooltip(fn ($record): ?string => $record->last_edited_at
                        ? $record->last_edited_at->format('d M Y, H:i').($record->lastEditor ? ' by '.$record->lastEditor->name : '')
                        : null
                    ),

                TextColumn::make('active_viewers')
                    ->label('')
                    ->getStateUsing(function (Project $record): ?string {
                        $viewers = $record->activeViewers;
                        if ($viewers->isEmpty()) {
                            return null;
                        }
                        $names = $viewers->map(fn ($u) => $u->name ?? $u->email);
                        $count = $names->count();
                        if ($count === 1) {
                            return $names->first().' is viewing this project';
                        }
                        if ($count === 2) {
                            return $names->first().' and '.$names->last().' are viewing this project';
                        }
                        $listed = $names->take(2)->implode(', ');

                        return $listed.' and '.($count - 2).' other'.($count > 3 ? 's' : '').' are viewing this project';
                    })
                    ->icon(fn (?string $state): string => $state ? 'heroicon-o-users' : '')
                    ->color('info')
                    ->formatStateUsing(fn (): string => '')
                    ->tooltip(fn (?string $state): ?string => $state),
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
                        $attributes['revision'] = 1;

                        // withoutEvents prevents the booted hook from auto-creating a revision+area
                        $copy = Project::withoutEvents(fn (): Project => Project::create($attributes));

                        // Manually create the initial revision for the copied project
                        $newRevision = ProjectRevision::create([
                            'project_id' => $copy->id,
                            'revision_number' => 1,
                            'created_by' => auth()->id(),
                        ]);

                        // Copy areas + lines from the source project's active revision
                        $sourceRevisionId = $record->active_revision_id;
                        $sourceAreas = $sourceRevisionId
                            ? ProjectArea::where('project_revision_id', $sourceRevisionId)
                                ->with('lines')
                                ->orderBy('sort_order')
                                ->get()
                            : collect();

                        foreach ($sourceAreas as $area) {
                            $newArea = ProjectArea::create([
                                'project_id' => $copy->id,
                                'project_revision_id' => $newRevision->id,
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

                        // Set the active revision on the copied project
                        $copy->updateQuietly(['active_revision_id' => $newRevision->id]);
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
            ->poll('60s')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('status', '!=', ProjectStatus::Archived->value)
                ->with(['activeViewers', 'lastEditor'])
            );
    }
}
