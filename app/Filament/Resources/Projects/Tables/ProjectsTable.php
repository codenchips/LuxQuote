<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Models\PermissionGroup;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectRevision;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('salesforce_project')
                    ->label('')
                    ->icon(fn (bool $state): string => $state ? 'heroicon-o-cloud' : 'heroicon-o-folder')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray')
                    ->tooltip(fn (bool $state): string => $state ? 'Salesforce project' : 'Standard project')
                    ->width('2rem'),

                TextColumn::make('reference_number')
                    ->label('Reference')
                    ->placeholder('–')
                    ->searchable()
                    ->sortable()
                    ->width('5.25rem')
                    ->extraCellAttributes(['style' => 'white-space: nowrap;'])
                    ->extraHeaderAttributes(['style' => 'white-space: nowrap;']),

                TextColumn::make('name')
                    ->label('Project Name')
                    ->searchable()
                    ->sortable()
                    ->limit(42)
                    ->tooltip(fn ($record): ?string => $record->name)
                    ->url(fn ($record) => ProjectResource::getUrl('view', ['record' => $record]))
                    ->grow()
                    ->lineClamp(1)
                    ->extraCellAttributes(['style' => 'min-width: 0; white-space: nowrap;'])
                    ->extraHeaderAttributes(['style' => 'white-space: nowrap;']),

                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => $state && mb_strlen($state) > 30 ? mb_substr($state, 0, 30).'...' : (string) $state)
                    ->tooltip(fn ($record): ?string => $record->customer_name && mb_strlen($record->customer_name) > 30 ? $record->customer_name : null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user.name')
                    ->label('Owner')
                    ->placeholder('–')
                    ->searchable()
                    ->tooltip(fn ($record): ?string => $record->user?->email)
                    ->limit(18)
                    ->lineClamp(1)
                    ->width('7.25rem')
                    ->extraCellAttributes(['style' => 'white-space: nowrap;'])
                    ->extraHeaderAttributes(['style' => 'white-space: nowrap;']),

                TextColumn::make('user.permissionGroup.name')
                    ->label('User Group')
                    ->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable()
                    ->width('6rem')
                    ->extraCellAttributes(['style' => 'white-space: nowrap;'])
                    ->extraHeaderAttributes(['style' => 'white-space: nowrap;']),

                TextColumn::make('revision')
                    ->label('Rev')
                    ->formatStateUsing(fn (int $state): string => ProjectRevision::labelForNumber($state))
                    ->badge()
                    ->color('gray')
                    ->width('3rem'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ProjectStatus $state): string => $state->label())
                    ->color(fn (ProjectStatus $state): string => $state->color())
                    ->sortable()
                    ->width('5.25rem'),

                TextColumn::make('visibility')
                    ->label('Visibility')
                    ->badge()
                    ->formatStateUsing(fn (ProjectVisibility $state): string => $state->label())
                    ->icon(fn (ProjectVisibility $state): string => match ($state) {
                        ProjectVisibility::Open => 'heroicon-o-globe-alt',
                        ProjectVisibility::Private => 'heroicon-o-lock-closed',
                    })
                    ->color(fn (ProjectVisibility $state): string => $state->color())
                    ->sortable()
                    ->width('5.75rem'),

                TextColumn::make('last_edited_at')
                    ->label('Last Edited')
                    ->since()
                    ->sortable()
                    ->placeholder('Never')
                    ->tooltip(fn ($record): ?string => $record->last_edited_at
                        ? $record->last_edited_at->format('M d Y H:i').($record->lastEditor ? ' by '.$record->lastEditor->name : '')
                        : null
                    )
                    ->width('6.25rem')
                    ->extraCellAttributes(['style' => 'white-space: nowrap;'])
                    ->extraHeaderAttributes(['style' => 'white-space: nowrap;']),

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
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->width('2rem'),
            ])
            ->filters([
                TernaryFilter::make('salesforce_project')
                    ->label('Project Type')
                    ->trueLabel('Salesforce')
                    ->falseLabel('Standard'),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => collect(ProjectStatus::cases())
                        ->mapWithKeys(fn (ProjectStatus $status): array => [$status->value => $status->label()])
                        ->all()
                    )
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $statuses = collect($data['values'] ?? [])
                            ->filter(fn (?string $status): bool => filled($status))
                            ->all();

                        if ($statuses === []) {
                            return $query->where('status', '!=', ProjectStatus::Archived->value);
                        }

                        return $query->whereIn('status', $statuses);
                    }),

                SelectFilter::make('user_group')
                    ->label('User Group')
                    ->default(fn (): ?int => auth()->user()?->permission_group_id)
                    ->options(fn (): array => PermissionGroup::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()
                    )
                    ->query(function (Builder $query, array $data): Builder {
                        $groupId = $data['value'] ?? null;

                        if (blank($groupId)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'user',
                            fn (Builder $query): Builder => $query->where('permission_group_id', $groupId),
                        );
                    }),
            ])
            ->persistFiltersInSession()
            ->actions([
                EditAction::make('editProject')
                    ->label('Details')
                    ->icon('heroicon-o-pencil')
                    ->iconButton()
                    ->tooltip(fn (Project $record): string => ProjectForm::projectDetailsAreReadOnly($record) ? 'View project details' : 'Edit project details')
                    ->color('gray')
                    ->form(fn (Schema $schema): Schema => ProjectForm::configure($schema))
                    ->slideOver()
                    ->visible(fn (): bool => auth()->user()?->can('projects.update-details') ?? false)
                    ->modalSubmitAction(fn (Action $action, Project $record): Action|false => ProjectForm::projectDetailsAreReadOnly($record) ? false : $action)
                    ->modalCancelActionLabel(fn (Project $record): string => ProjectForm::projectDetailsAreReadOnly($record) ? 'Close' : 'Cancel')
                    ->using(function (Project $record, array $data): void {
                        abort_if(ProjectForm::projectDetailsAreReadOnly($record), 403, 'Approved revisions are locked against editing.');

                        $record->update($data);
                    }),

                Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->iconButton()
                    ->tooltip('Copy project')
                    ->visible(fn (): bool => auth()->user()?->can('projects.create') ?? false)
                    ->action(function (Project $record): void {
                        $attributes = $record->only([
                            'user_id', 'name', 'customer_name', 'contractor', 'site_location',
                            'owner_email', 'created_by_email', 'department', 'date', 'revision',
                            'visibility', 'status', 'branch_name', 'cover_percentage', 'value',
                            'quote_notes', 'internal_notes', 'general_notes',
                        ]);

                        $attributes['name'] = $record->name.' - Copy';
                        $attributes['reference_number'] = null;
                        $attributes['revision'] = 0;

                        // withoutEvents prevents the booted hook from auto-creating a revision+area
                        $copy = Project::withoutEvents(fn (): Project => Project::create($attributes));

                        // Manually create the initial revision for the copied project
                        $newRevision = ProjectRevision::create([
                            'project_id' => $copy->id,
                            'revision_number' => 0,
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
                        $copy->syncStatusFromActiveRevision();
                    }),

                ActionGroup::make([
                    Action::make('archive')
                        ->label('Archive')
                        ->icon('heroicon-o-archive-box')
                        ->color('warning')
                        ->visible(fn (): bool => auth()->user()?->can('projects.update-details') ?? false)
                        ->action(fn (Project $record) => $record->update(['status' => ProjectStatus::Archived])),

                    Action::make('delete')
                        ->label('Delete permanently')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => auth()->user()?->isAdministrator() ?? false)
                        ->modalHeading('Delete project permanently?')
                        ->modalDescription('This will permanently delete the project and all its areas and lines. This cannot be undone.')
                        ->modalSubmitActionLabel('Yes, delete permanently')
                        ->action(fn (Project $record) => $record->delete()),
                ])
                    ->icon('heroicon-o-trash')
                    ->color('gray')
                    ->tooltip('Delete / Archive')
                    ->visible(fn (): bool => (auth()->user()?->can('projects.update-details') ?? false) || (auth()->user()?->isAdministrator() ?? false)),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('60s')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['activeViewers', 'lastEditor', 'user.permissionGroup'])
            );
    }
}
