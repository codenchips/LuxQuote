<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\ProjectVisibility;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Project Name')
                    ->placeholder('e.g. Office Fit-Out 2026')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),

                TextInput::make('reference_number')
                    ->label('Reference Number')
                    ->placeholder('e.g. LQ-2026-001')
                    ->unique(ignoreRecord: true),

                TextInput::make('customer_name')
                    ->label('Customer Name')
                    ->placeholder('Customer')
                    ->required(),

                TextInput::make('contractor')
                    ->label('Contractor')
                    ->placeholder('Contractor'),

                TextInput::make('site_location')
                    ->label('Site Location')
                    ->placeholder('Location'),

                TextInput::make('owner_email')
                    ->label('Project Owner (email)')
                    ->placeholder('owner@company.com')
                    ->email()
                    ->default(fn (): ?string => auth()->user()?->email),

                TextInput::make('created_by_email')
                    ->label('Created By (email)')
                    ->placeholder('creator@company.com')
                    ->email()
                    ->default(fn (): ?string => auth()->user()?->email),

                Select::make('department')
                    ->label('Department')
                    ->placeholder('Select department...')
                    ->options([
                        'sales' => 'Sales',
                        'design' => 'Design',
                        'operations' => 'Operations',
                        'finance' => 'Finance',
                    ]),

                DatePicker::make('date')
                    ->label('Date')
                    ->default(now()),

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
                    ->columnSpanFull(),

                TextInput::make('branch_name')
                    ->label('Branch Name')
                    ->placeholder('e.g. Birmingham Central'),

                TextInput::make('cover_percentage')
                    ->label('Cover Percentage (%)')
                    ->placeholder('e.g. 15')
                    ->numeric()
                    ->helperText('Applied to quote total only — not per line.')
                    ->suffix('%'),

                Textarea::make('quote_notes')
                    ->label('Quote Notes (shown on quote document)')
                    ->placeholder('Notes visible on the quote PDF...')
                    ->rows(3)
                    ->columnSpanFull(),

                Textarea::make('internal_notes')
                    ->label('Internal Notes (not shown on documents)')
                    ->placeholder('Internal team notes...')
                    ->rows(3)
                    ->columnSpanFull(),

                Textarea::make('general_notes')
                    ->label('General Notes')
                    ->placeholder('Project notes...')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
