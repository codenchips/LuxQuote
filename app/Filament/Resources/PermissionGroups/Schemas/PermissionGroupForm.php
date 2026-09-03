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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PermissionGroupForm
{
    /** @var array<string, array<int, string>> */
    private const PermissionAreas = [
        'Project' => ['Projects', 'Revisions', 'History', 'Output'],
        'Users' => ['Users & Admin'],
        'Calendar' => ['Calendar'],
        'Pricing' => ['Pricing'],
        'Salesforce' => ['Salesforce'],
        'Products' => ['Products'],
        'Resources' => ['Resources'],
        'Validation' => ['Validation'],
    ];

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
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Permissions')
                    ->description('Choose the app capabilities this group should have.')
                    ->schema([
                        TextInput::make('permission_search')
                            ->label('Search permissions')
                            ->placeholder('Search all permissions')
                            ->extraAlpineAttributes([
                                'x-on:input.debounce.300ms' => '$dispatch(\'permission-search\', $event.target.value)',
                            ])
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        ...self::permissionAreaSections(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function permissionAreas(): array
    {
        return self::PermissionAreas;
    }

    /**
     * @return array<int, Section>
     */
    private static function permissionAreaSections(): array
    {
        return collect(self::PermissionAreas)
            ->map(function (array $categories, string $area): Section {
                $statePath = Str::snake($area).'_permissions';
                $isProjectArea = $area === 'Project';

                $section = Section::make($area)
                    ->schema([
                        CheckboxList::make($statePath)
                            ->hiddenLabel()
                            ->relationship(
                                name: 'permissions',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->whereIn('category', $categories)
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->extraAlpineAttributes([
                                'class' => 'lux-shared-permission-search-list',
                                'x-on:permission-search.window' => 'search = $event.detail',
                            ])
                            ->bulkToggleable()
                            ->columns($isProjectArea ? 3 : 2),
                    ]);

                return $isProjectArea ? $section->columnSpanFull() : $section;
            })
            ->values()
            ->all();
    }
}
