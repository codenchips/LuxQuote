<?php

namespace App\Filament\Resources\Projects\Schemas;

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
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

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
                    ->default(false)
                    ->disabled(fn (?Project $record): bool => $record !== null)
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Project Name')
                    ->placeholder('e.g. Office Fit-Out 2026')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->hidden(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true && $record === null)
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true)
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
                        if (blank($state)) {
                            $set('name', null);
                            $set('salesforce_pending_data', null);

                            return;
                        }

                        $record = app(SalesforceService::class)->getOpportunityById($state);

                        if ($record === null) {
                            return;
                        }

                        $set('name', $record['Name'] ?? '');
                        $set('salesforce_pending_data', json_encode($record));
                    })
                    ->visible(fn (Get $get, ?Project $record): bool => $get('salesforce_project') === true && $record === null)
                    ->columnSpanFull(),

                Hidden::make('salesforce_pending_data'),

                Actions::make([
                    Action::make('confirm_salesforce')
                        ->label('Confirm & Populate Form')
                        ->color('success')
                        ->icon('heroicon-o-check-circle')
                        ->action(function (Get $get, Set $set): void {
                            $raw = $get('salesforce_pending_data');

                            if (blank($raw)) {
                                return;
                            }

                            $data = json_decode($raw, true);

                            if (! is_array($data)) {
                                return;
                            }

                            $set('reference_number', $data['Project_Reference_Number__c'] ?? '');
                            $set('customer_name', $data['Account']['Name'] ?? '');
                            $set('owner_email', str_replace('.invalid', '', $data['Owner']['Email'] ?? ''));
                        }),
                ])->visible(fn (Get $get, ?Project $record): bool => $record === null && $get('salesforce_project') === true && filled($get('salesforce_id'))),

                TextInput::make('reference_number')
                    ->label('Reference Number')
                    ->placeholder('e.g. LQ-2026-001')
                    ->unique(ignoreRecord: true)
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true),

                TextInput::make('customer_name')
                    ->label('Customer Name')
                    ->placeholder('Customer')
                    ->required()
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true),

                TextInput::make('contractor')
                    ->label('Contractor')
                    ->placeholder('Contractor')
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true),

                TextInput::make('site_location')
                    ->label('Site Location')
                    ->placeholder('Location')
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true),

                TextInput::make('owner_email')
                    ->label('Project Owner (email)')
                    ->placeholder('owner@company.com')
                    ->email()
                    ->default(fn (): ?string => auth()->user()?->email)
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true),

                TextInput::make('created_by_email')
                    ->label('Created By (email)')
                    ->placeholder('creator@company.com')
                    ->email()
                    ->default(fn (): ?string => auth()->user()?->email)
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true),

                Select::make('department')
                    ->label('Department')
                    ->placeholder('Select department...')
                    ->options([
                        'sales' => 'Sales',
                        'design' => 'Design',
                        'operations' => 'Operations',
                        'finance' => 'Finance',
                    ])
                    ->disabled(fn (Get $get): bool => $get('salesforce_project') === true),

                DatePicker::make('date')
                    ->label('Date')
                    ->default(now())
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true),

                TextInput::make('revision')
                    ->label('Revision Number')
                    ->numeric()
                    ->default(1)
                    ->readOnly()
                    ->helperText('Managed via the Revisions action on the project page.'),

                ToggleButtons::make('visibility')
                    ->label('Project Visibility')
                    ->hint(fn (?ProjectVisibility $state): string => match ($state) {
                        ProjectVisibility::Private => 'Only you can see this project.',
                        default => 'All logged-in users can see this project.',
                    })
                    ->options(ProjectVisibility::class)
                    ->default(ProjectVisibility::Open)
                    ->inline()
                    ->disabled(fn (Get $get): bool => $get('salesforce_project') === true)
                    ->columnSpanFull(),

                TextInput::make('branch_name')
                    ->label('Branch Name')
                    ->placeholder('e.g. Birmingham Central')
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true),

                TextInput::make('cover_percentage')
                    ->label('Cover Percentage (%)')
                    ->placeholder('e.g. 15')
                    ->numeric()
                    ->helperText('Applied to quote total only — not per line.')
                    ->suffix('%')
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true),

                Textarea::make('quote_notes')
                    ->label('Quote Notes (shown on quote document)')
                    ->placeholder('Notes visible on the quote PDF...')
                    ->rows(3)
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true)
                    ->columnSpanFull(),

                Textarea::make('internal_notes')
                    ->label('Internal Notes (not shown on documents)')
                    ->placeholder('Internal team notes...')
                    ->rows(3)
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true)
                    ->columnSpanFull(),

                Textarea::make('general_notes')
                    ->label('General Notes')
                    ->placeholder('Project notes...')
                    ->rows(3)
                    ->readOnly(fn (Get $get): bool => $get('salesforce_project') === true)
                    ->columnSpanFull(),
            ]);
    }
}
