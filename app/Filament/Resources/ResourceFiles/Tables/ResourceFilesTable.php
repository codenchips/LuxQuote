<?php

namespace App\Filament\Resources\ResourceFiles\Tables;

use App\Enums\PermissionKey;
use App\Models\ResourceFile;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class ResourceFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('extension')
                    ->label('File Type')
                    ->badge()
                    ->icon(fn (string $state): Heroicon => self::fileTypeIcon($state))
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->sortable(),
                TextColumn::make('display_name')
                    ->label('Display Name')
                    ->tooltip(fn (ResourceFile $record): string => $record->original_filename)
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Date Added')
                    ->dateTime('M d Y H:i')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->tooltip('View file')
                    ->authorize(PermissionKey::ResourcesView->value)
                    ->modalHeading(fn (ResourceFile $record): string => $record->display_name)
                    ->modalDescription(fn (ResourceFile $record): string => $record->original_filename)
                    ->modalContent(fn (ResourceFile $record): View => view(
                        'filament.resources.resource-files.preview',
                        [
                            'resourceFile' => $record,
                            'previewUrl' => route('resource-files.view', $record),
                        ],
                    ))
                    ->modalWidth(Width::ScreenExtraLarge)
                    ->modalAutofocus(false)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Edit display name')
                    ->authorize(PermissionKey::ResourcesUpdate->value)
                    ->modalHeading('Edit display name')
                    ->modalDescription(fn (ResourceFile $record): string => $record->original_filename)
                    ->modalWidth(Width::Medium)
                    ->schema([
                        TextInput::make('display_name')
                            ->label('Display name')
                            ->required()
                            ->maxLength(255),
                    ]),
                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Delete')
                    ->authorize(PermissionKey::ResourcesDelete->value)
                    ->modalHeading('Delete resource')
                    ->modalDescription('Delete this resource and its stored file? This cannot be undone.'),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    private static function fileTypeIcon(string $extension): Heroicon
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png', 'gif', 'webp' => Heroicon::OutlinedPhoto,
            'xls', 'xlsx', 'csv' => Heroicon::OutlinedTableCells,
            'ppt', 'pptx' => Heroicon::OutlinedPresentationChartBar,
            default => Heroicon::OutlinedDocumentText,
        };
    }
}
