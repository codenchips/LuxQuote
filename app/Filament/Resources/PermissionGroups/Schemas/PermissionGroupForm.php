<?php

namespace App\Filament\Resources\PermissionGroups\Schemas;

use App\Enums\LandingPage;
use App\Models\PermissionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PermissionGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Group Details')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (?PermissionGroup $record): bool => (bool) $record?->is_system)
                            ->dehydrated(fn (?PermissionGroup $record): bool => ! (bool) $record?->is_system),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Select::make('default_landing_page')
                            ->label('Landing page')
                            ->options(LandingPage::groupedOptions())
                            ->default(LandingPage::Dashboard->value)
                            ->required()
                            ->native(false)
                            ->helperText('Users in this group will open here after login. Dashboard is used if they do not have access to the selected page.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Permissions')
                    ->description('Choose the app capabilities this group should have.')
                    ->schema([
                        CheckboxList::make('permissions')
                            ->relationship(
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($query) => $query
                                    ->orderBy('category')
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(2),
                    ]),
            ]);
    }
}
