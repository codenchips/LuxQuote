<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\ProjectRevisionStatus;
use App\Enums\ProjectVisibility;
use App\Models\Project;
use App\Services\SalesforceService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Toggle::make('salesforce_project')
                    ->label('Salesforce Project')
                    ->live()
                    ->default(true)
                    ->afterStateUpdated(function (bool $state, Set $set): void {
                        if ($state) {
                            self::clearSalesforceSelection($set);
                        }
                    })
                    ->disabled(fn (?Project $record): bool => $record !== null)
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Project Name')
                    ->placeholder('e.g. Office Fit-Out 2026')
                    ->live()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'A project with this project name already exists.',
                    ])
                    ->hidden(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true && $record === null)
                    ->readOnly(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true || self::projectDetailsAreReadOnly($record))
                    ->columnSpanFull(),

                Select::make('salesforce_id')
                    ->label('Project Name')
                    ->placeholder('Type to search Salesforce projects...')
                    ->searchable()
                    ->getSearchResultsUsing(
                        fn (string $search): array => app(SalesforceService::class)->searchOpportunities($search)
                    )
                    ->getOptionLabelUsing(function (?string $value): ?string {
                        if (blank($value)) {
                            return null;
                        }

                        $record = app(SalesforceService::class)->getOpportunityById($value);

                        return $record ? $record['Name'] : null;
                    })
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        self::selectSalesforceOpportunity($state, $set);
                    })
                    ->visible(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true && $record === null)
                    ->columnSpanFull(),

                Select::make('salesforce_reference_id')
                    ->label('Reference Number')
                    ->placeholder('Type to search Salesforce project references...')
                    ->searchable()
                    ->getSearchResultsUsing(
                        fn (string $search): array => app(SalesforceService::class)->searchOpportunitiesByReference($search)
                    )
                    ->getOptionLabelUsing(function (?string $value): ?string {
                        if (blank($value)) {
                            return null;
                        }

                        $record = app(SalesforceService::class)->getOpportunityById($value);

                        return $record ? self::salesforceSelectedReferenceLabel($record) : null;
                    })
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        self::selectSalesforceOpportunity($state, $set);
                    })
                    ->visible(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true
                        && $record === null
                        && blank($get('salesforce_id')))
                    ->dehydrated(false),

                Html::make(<<<'HTML'
                    <div
                        wire:loading.delay
                        wire:loading.class.remove="hidden"
                        wire:loading.class="flex"
                        class="hidden items-center justify-end gap-2 text-sm text-gray-500 dark:text-gray-400"
                    >
                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-warning-500 border-t-transparent"></span>
                        <span>Fetching Salesforce project...</span>
                    </div>
                    HTML)
                    ->columnSpanFull()
                    ->visible(fn (Get $get, ?Project $record): bool => $record === null && $get('salesforce_project') === true),

                Hidden::make('salesforce_pending_data'),

                Actions::make([
                    Action::make('confirm_salesforce')
                        ->label('Confirm & Populate Form')
                        ->color('warning')
                        ->icon('heroicon-o-check-circle')
                        ->action(function (Get $get, Set $set): void {
                            $raw = $get('salesforce_pending_data');

                            if (blank($raw)) {
                                Notification::make()
                                    ->warning()
                                    ->title('Salesforce project details were not loaded')
                                    ->body('Select the Salesforce project again. If this repeats, check the Salesforce field permissions for the integration user.')
                                    ->send();

                                return;
                            }

                            $data = json_decode($raw, true);

                            if (! is_array($data)) {
                                Notification::make()
                                    ->warning()
                                    ->title('Salesforce project details were not loaded')
                                    ->body('The selected Salesforce project returned an unexpected response. Select it again and try once more.')
                                    ->send();

                                return;
                            }

                            $set('reference_number', $data['Project_Reference_Number__c'] ?? '');
                            $set('customer_name', $data['Miscellaneous_Customer_Name__c'] ?? $data['Account']['Name'] ?? $data['Name'] ?? '');
                            $set('owner_email', str_replace('.invalid', '', $data['Owner']['Email'] ?? ''));
                            $hasSalesforceCover = filled($data['CEF_Cover__c'] ?? null);
                            $set('has_cover', $hasSalesforceCover);
                            $set('cover_direction', 'deducted');
                            $set('cover_1', $hasSalesforceCover ? $data['CEF_Cover__c'] : null);
                            $set('cover_2', $hasSalesforceCover ? '5.00' : null);
                            $set('cover_3', $hasSalesforceCover ? '5.00' : null);
                            $set('value', $data['Amount'] ?? null);
                        }),
                ])
                    ->alignStart()
                    ->columnSpanFull()
                    ->visible(fn (Get $get, ?Project $record): bool => $record === null && $get('salesforce_project') === true && filled($get('salesforce_id'))),

                TextInput::make('reference_number')
                    ->label('Reference Number')
                    ->placeholder('e.g. LQ-2026-001')
                    ->live()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'A project with this reference number already exists.',
                    ])
                    ->hidden(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true
                        && $record === null
                        && blank($get('salesforce_id')))
                    ->dehydratedWhenHidden()
                    ->readOnly(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true || self::projectDetailsAreReadOnly($record)),

                TextInput::make('customer_name')
                    ->label('Customer Name')
                    ->placeholder('Customer')
                    ->live()
                    ->required()
                    ->readOnly(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true || self::projectDetailsAreReadOnly($record)),

                TextInput::make('site_location')
                    ->label('Site Location')
                    ->placeholder('Location')
                    ->readOnly(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true || self::projectDetailsAreReadOnly($record)),

                TextInput::make('owner_email')
                    ->label('Project Owner (email)')
                    ->placeholder('owner@company.com')
                    ->email()
                    ->readOnly(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true || self::projectDetailsAreReadOnly($record)),

                TextInput::make('created_by_email')
                    ->label('Created By (email)')
                    ->placeholder('creator@company.com')
                    ->email()
                    ->default(fn (): ?string => auth()->user()?->email)
                    ->readOnly(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true || self::projectDetailsAreReadOnly($record)),

                DatePicker::make('date')
                    ->label('Date')
                    ->default(now())
                    ->readOnly(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true || self::projectDetailsAreReadOnly($record)),

                Hidden::make('visibility')
                    ->default(ProjectVisibility::Open->value),

                Hidden::make('team_id'),

                Select::make('visibility_target')
                    ->label('Project Visibility')
                    ->options(fn (?Project $record): array => self::visibilityTargetOptions($record))
                    ->default(fn (?Project $record): string => self::visibilityTargetForRecord($record))
                    ->afterStateHydrated(function (Select $component, ?Project $record): void {
                        $component->state(self::visibilityTargetForRecord($record));
                    })
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        self::applyVisibilityTarget($state, $set);
                    })
                    ->helperText(fn (Get $get): string => self::visibilityTargetHint((string) $get('visibility_target')))
                    ->searchable()
                    ->dehydrated(false)
                    ->required()
                    ->disabled(fn (?Project $record): bool => self::projectDetailsAreReadOnly($record))
                    ->columnSpanFull(),

                TextInput::make('value')
                    ->label('Value')
                    ->placeholder('0.00')
                    ->numeric()
                    ->visible(fn (): bool => auth()->user()?->can('pricing.view') ?? false)
                    ->prefix('£')
                    ->readOnly(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true || self::projectDetailsAreReadOnly($record)),

                TextInput::make('branch_name')
                    ->label('Branch Name')
                    ->placeholder('e.g. Birmingham Central')
                    ->readOnly(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true || self::projectDetailsAreReadOnly($record)),

                Toggle::make('has_cover')
                    ->label('Has Cover')
                    ->default(false)
                    ->live()
                    ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                        if (! $state) {
                            $set('cover_direction', 'deducted');
                            $set('cover_1', null);
                            $set('cover_2', null);
                            $set('cover_3', null);

                            return;
                        }

                        foreach (['cover_1', 'cover_2', 'cover_3'] as $field) {
                            if (blank($get($field))) {
                                $set($field, '5.00');
                            }
                        }

                        if (blank($get('cover_direction'))) {
                            $set('cover_direction', 'deducted');
                        }
                    })
                    ->visible(fn (): bool => auth()->user()?->can('pricing.view') ?? false)
                    ->disabled(fn (?Project $record): bool => ! (auth()->user()?->can('cover.update') ?? false) || self::projectDetailsAreReadOnly($record)),

                ToggleButtons::make('cover_direction')
                    ->label('Cover Direction')
                    ->hiddenLabel()
                    ->options([
                        'deducted' => 'Cover is Deducted',
                        'added' => 'Cover is Added',
                    ])
                    ->default('deducted')
                    ->grouped()
                    ->visible(fn (Get $get): bool => (auth()->user()?->can('pricing.view') ?? false) && (bool) $get('has_cover'))
                    ->disabled(fn (?Project $record): bool => ! (auth()->user()?->can('cover.update') ?? false) || self::projectDetailsAreReadOnly($record)),

                Grid::make(3)
                    ->schema([
                        self::coverInput('cover_1', 'Cover 1'),
                        self::coverInput('cover_2', 'Cover 2'),
                        self::coverInput('cover_3', 'Cover 3'),
                    ])
                    ->visible(fn (Get $get): bool => (auth()->user()?->can('pricing.view') ?? false) && (bool) $get('has_cover'))
                    ->columnSpanFull(),

                Textarea::make('quote_notes')
                    ->label('Quote Notes (shown on quote document)')
                    ->placeholder('Notes visible on the quote PDF...')
                    ->rows(3)
                    ->readOnly(fn (?Project $record): bool => self::projectDetailsAreReadOnly($record))
                    ->columnSpanFull(),

                Textarea::make('internal_notes')
                    ->label('Internal Notes (not shown on documents)')
                    ->placeholder('Internal team notes...')
                    ->rows(3)
                    ->readOnly(fn (?Project $record): bool => self::projectDetailsAreReadOnly($record))
                    ->columnSpanFull(),

                Textarea::make('general_notes')
                    ->label('General Notes')
                    ->placeholder('Project notes...')
                    ->rows(3)
                    ->readOnly(fn (?Project $record): bool => self::projectDetailsAreReadOnly($record))
                    ->columnSpanFull(),
            ]);
    }

    public static function projectDetailsAreReadOnly(?Project $record): bool
    {
        if ($record === null) {
            return false;
        }

        $activeRevision = $record->activeRevision ?? $record->activeRevision()->first();

        return $activeRevision?->status === ProjectRevisionStatus::Approved;
    }

    public static function titleCaseProjectName(?string $name): string
    {
        return (string) preg_replace_callback(
            '/\b[\pL\pN][\pL\pN\']*\b/u',
            fn (array $matches): string => self::titleCaseProjectNameWord($matches[0]),
            (string) $name,
        );
    }

    private static function titleCaseProjectNameWord(string $word): string
    {
        if (preg_match('/^\p{Lu}{2,}$/u', $word) === 1 && mb_strlen($word) <= 3) {
            return $word;
        }

        return Str::of($word)
            ->lower()
            ->title()
            ->toString();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function salesforceSelectedReferenceLabel(array $record): string
    {
        return (string) ($record['Project_Reference_Number__c'] ?? '');
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @param  array<string, mixed>|null  $fallbackState
     */
    public static function createActionIsDisabled(?array $state, ?array $fallbackState = null): bool
    {
        $requiredFields = ['name', 'customer_name', 'reference_number'];

        foreach ($requiredFields as $field) {
            if (blank($state[$field] ?? null) && blank($fallbackState[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normaliseVisibilityData(array $data, ?Project $record = null): array
    {
        $data = self::normaliseCoverData($data, $record);

        $visibility = $data['visibility'] ?? ProjectVisibility::Open->value;
        $teamId = $data['team_id'] ?? null;

        if ($visibility instanceof ProjectVisibility) {
            $visibility = $visibility->value;
        }

        if ($visibility !== ProjectVisibility::Team->value) {
            $data['visibility'] = $visibility;
            $data['team_id'] = null;

            return $data;
        }

        $allowedTeamIds = auth()->user()?->teams()->pluck('teams.id')->all() ?? [];

        if ($record?->team_id !== null) {
            $allowedTeamIds[] = $record->team_id;
        }

        if (blank($teamId) || ! in_array((int) $teamId, array_map('intval', $allowedTeamIds), true)) {
            $data['visibility'] = ProjectVisibility::Private->value;
            $data['team_id'] = null;

            return $data;
        }

        $data['visibility'] = ProjectVisibility::Team->value;
        $data['team_id'] = (int) $teamId;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normaliseCoverData(array $data, ?Project $record): array
    {
        if ((auth()->user()?->can('pricing.view') ?? false) && (auth()->user()?->can('cover.update') ?? false)) {
            if (! array_key_exists('has_cover', $data)) {
                if ($record === null) {
                    unset($data['has_cover']);
                    unset($data['cover_direction']);

                    foreach (['cover_1', 'cover_2', 'cover_3'] as $field) {
                        unset($data[$field]);
                    }

                    return $data;
                }

                $data['has_cover'] = $record->has_cover;
                $data['cover_direction'] = $record->cover_direction;

                foreach (['cover_1', 'cover_2', 'cover_3'] as $field) {
                    $data[$field] = $record->{$field};
                }

                return $data;
            }

            $data['has_cover'] = (bool) ($data['has_cover'] ?? false);

            if (! $data['has_cover']) {
                $data['cover_direction'] = 'deducted';
                $data['cover_1'] = null;
                $data['cover_2'] = null;
                $data['cover_3'] = null;

                return $data;
            }

            foreach (['cover_1', 'cover_2', 'cover_3'] as $field) {
                if (($data[$field] ?? null) === '' || ($data[$field] ?? null) === null) {
                    $data[$field] = '5.00';

                    continue;
                }

                $data[$field] = number_format(min(999.99, max(0, (float) $data[$field])), 2, '.', '');
            }

            if (! in_array($data['cover_direction'] ?? null, ['added', 'deducted'], true)) {
                $data['cover_direction'] = 'deducted';
            }

            return $data;
        }

        foreach (['cover_1', 'cover_2', 'cover_3'] as $field) {
            if ($record === null) {
                unset($data[$field]);

                continue;
            }

            $data[$field] = $record->{$field};
        }

        if ($record === null) {
            unset($data['has_cover']);
            unset($data['cover_direction']);
        } else {
            $data['has_cover'] = $record->has_cover;
            $data['cover_direction'] = $record->cover_direction;
        }

        return $data;
    }

    private static function coverInput(string $field, string $label): TextInput
    {
        return TextInput::make($field)
            ->label($label)
            ->placeholder('5.00')
            ->numeric()
            ->suffix('%')
            ->minValue(0)
            ->maxValue(999.99)
            ->extraInputAttributes(['step' => '0.01'])
            ->readOnly(fn (?Project $record): bool => ! (auth()->user()?->can('cover.update') ?? false) || self::projectDetailsAreReadOnly($record));
    }

    /**
     * @return array<string, string>
     */
    private static function visibilityTargetOptions(?Project $record): array
    {
        $options = [
            ProjectVisibility::Open->value => 'Open',
            ProjectVisibility::Private->value => 'Private',
        ];

        $teams = auth()->user()?->teams()
            ->orderBy('name')
            ->pluck('name', 'teams.id')
            ->all() ?? [];

        if ($record?->team_id !== null && ! array_key_exists($record->team_id, $teams)) {
            $record->loadMissing('team');

            if ($record->team !== null) {
                $teams[$record->team_id] = $record->team->name;
            }
        }

        foreach ($teams as $id => $name) {
            $options["team:{$id}"] = "Team: {$name}";
        }

        return $options;
    }

    private static function visibilityTargetForRecord(?Project $record): string
    {
        if ($record?->visibility === ProjectVisibility::Team && $record->team_id !== null) {
            return "team:{$record->team_id}";
        }

        return $record?->visibility?->value ?? ProjectVisibility::Open->value;
    }

    private static function applyVisibilityTarget(?string $state, Set $set): void
    {
        if (str_starts_with((string) $state, 'team:')) {
            $set('visibility', ProjectVisibility::Team->value);
            $set('team_id', (int) str_replace('team:', '', (string) $state));

            return;
        }

        $set('visibility', $state === ProjectVisibility::Private->value
            ? ProjectVisibility::Private->value
            : ProjectVisibility::Open->value);
        $set('team_id', null);
    }

    private static function visibilityTargetHint(string $target): string
    {
        if ($target === ProjectVisibility::Private->value) {
            return 'Only you can see this project.';
        }

        if (str_starts_with($target, 'team:')) {
            return 'Only you and members of the selected team can see this project.';
        }

        return 'All logged-in users can see this project.';
    }

    private static function selectSalesforceOpportunity(?string $salesforceId, Set $set): void
    {
        if (blank($salesforceId)) {
            self::clearSalesforceSelection($set);

            return;
        }

        self::clearUnconfirmedSalesforceDetails($set);

        $record = app(SalesforceService::class)->getOpportunityById($salesforceId);

        if ($record === null) {
            Notification::make()
                ->warning()
                ->title('Salesforce project details were not loaded')
                ->body('The project appeared in search, but its details could not be fetched. Check Salesforce field permissions for the integration user.')
                ->send();

            return;
        }

        $set('name', self::titleCaseProjectName($record['Name'] ?? ''));
        $set('reference_number', $record['Project_Reference_Number__c'] ?? null);
        $set('salesforce_id', $record['Id'] ?? null);
        $set('salesforce_reference_id', $record['Id'] ?? null);
        $set('salesforce_pending_data', json_encode($record));
    }

    private static function clearSalesforceSelection(Set $set): void
    {
        self::clearUnconfirmedSalesforceDetails($set);

        $set('name', null);
        $set('reference_number', null);
        $set('salesforce_id', null);
        $set('salesforce_reference_id', null);
        $set('salesforce_pending_data', null);
    }

    private static function clearUnconfirmedSalesforceDetails(Set $set): void
    {
        foreach ([
            'customer_name',
            'site_location',
            'owner_email',
            'branch_name',
            'cover_percentage',
            'cover_1',
            'cover_2',
            'cover_3',
            'value',
            'quote_notes',
            'internal_notes',
            'general_notes',
        ] as $field) {
            $set($field, null);
        }

        $set('created_by_email', auth()->user()?->email);
        $set('date', now()->toDateString());
    }
}
