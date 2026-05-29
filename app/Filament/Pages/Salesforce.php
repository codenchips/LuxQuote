<?php

namespace App\Filament\Pages;

use App\Services\SalesforceService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

class Salesforce extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Salesforce';

    protected static ?string $title = 'Salesforce Projects';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.salesforce';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-salesforce') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (
                ?string $sortColumn,
                ?string $sortDirection,
                ?string $search,
                ?array $filters,
                int $page,
                int $recordsPerPage,
            ): LengthAwarePaginator {
                return app(SalesforceService::class)->getOpportunities(
                    page: $page,
                    perPage: $recordsPerPage,
                    search: $search,
                    sortColumn: $sortColumn,
                    sortDirection: $sortDirection,
                    fields: ['Id', 'Project_Reference_Number__c', 'Name', 'StageName', 'CreatedDate', 'Amount', 'Owner.Name', 'Owner.Email'],
                );
            })
            ->columns([
                TextColumn::make('Project_Reference_Number__c')
                    ->label('Reference')
                    ->sortable(),
                TextColumn::make('Name')
                    ->label('Project Name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('StageName')
                    ->label('Stage')
                    ->badge()
                    ->sortable(),
                TextColumn::make('Amount')
                    ->label('Amount')
                    ->money('GBP')
                    ->sortable(),
                TextColumn::make('CreatedDate')
                    ->label('Created')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('Owner.Name')
                    ->label('Owner')
                    ->tooltip(fn (array $record): string => str_replace('.invalid', '', $record['Owner']['Email'] ?? ''))
                    ->sortable(),
            ])
            ->searchable()
            ->defaultSort('CreatedDate', 'desc')
            ->striped()
            ->paginated([10, 25, 50]);
    }
}
