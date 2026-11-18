<?php

namespace App\Filament\Pages;

use App\Filament\Support\BadgeStyle;
use App\Filament\Support\SectionNavigation;
use App\Services\SalesforcePushControl;
use App\Services\SalesforceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;
use UnitEnum;

class Salesforce extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Salesforce';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Salesforce';

    protected static ?string $title = 'Salesforce Projects';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.salesforce';

    public bool $salesforcePushDisabled = false;

    public function mount(): void
    {
        $this->salesforcePushDisabled = app(SalesforcePushControl::class)->disabled();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('salesforce.view') ?? false;
    }

    /** @return array<array{label: string, url: string, icon: string, active: bool}> */
    public static function subNavigationLinks(?string $currentUrl = null): array
    {
        return SectionNavigation::links('salesforce', $currentUrl);
    }

    public function getHeader(): ?View
    {
        return view('filament.resources.projects.pages.project-header', [
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'actions' => $this->getCachedHeaderActions(),
            'actionsAlignment' => $this->getHeaderActionsAlignment(),
            'subLinks' => self::subNavigationLinks(static::getUrl()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('salesforcePushControl')
                ->view('filament.pages.actions.salesforce-push-control', fn ($livewire): array => [
                    'canManageSalesforcePush' => $livewire->canManageSalesforcePush(),
                    'salesforcePushDisabled' => $livewire->salesforcePushDisabled,
                ]),
        ];
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
                abort_unless(static::canAccess(), 403);
                $canViewPrices = auth()->user()?->can('pricing.view') ?? false;
                $fields = ['Id', 'Project_Reference_Number__c', 'Name', 'StageName', 'CreatedDate', 'Owner.Name', 'Owner.Email'];

                if ($canViewPrices) {
                    $fields[] = 'Amount';
                }

                return app(SalesforceService::class)->getOpportunities(
                    page: $page,
                    perPage: $recordsPerPage,
                    search: $search,
                    sortColumn: ! $canViewPrices && $sortColumn === 'Amount' ? null : $sortColumn,
                    sortDirection: $sortDirection,
                    fields: $fields,
                    stages: $filters['stage']['values'] ?? [],
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
                    ->color(fn (?string $state): string|array => BadgeStyle::filamentColor($state))
                    ->sortable(),
                TextColumn::make('Amount')
                    ->label('Amount')
                    ->visible(fn (): bool => auth()->user()?->can('pricing.view') ?? false)
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
            ->recordActions([$this->recordDetailsAction()])
            ->recordAction('viewRecord')
            ->searchable()
            ->defaultSort('CreatedDate', 'desc')
            ->filters([
                SelectFilter::make('stage')->label('Stage')->multiple()
                    ->options(fn (): array => app(SalesforceService::class)->getOpportunityStageOptions()),
            ])
            ->persistFiltersInSession()
            ->paginated([10, 25, 50]);
    }

    protected function recordDetailsAction(): Action
    {
        return Action::make('viewRecord')
            ->label('View details')->icon('heroicon-o-eye')->iconButton()->tooltip('View details')
            ->authorize(fn (): bool => static::canAccess())
            ->modalHeading($this instanceof SalesforceCustomers ? 'Customer details' : 'Project details')
            ->modalWidth('4xl')
            ->schema($this->recordDetailsSchema())
            ->disabledSchema(fn (): bool => ! $this->canEditRecordDefaults())
            ->fillForm(function (array $record, Action $action): array {
                abort_unless(static::canAccess(), 403);

                try {
                    $details = $this->loadRecordDetails((string) ($record['Id'] ?? ''));

                    if ($details !== null) {
                        return $details;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }

                Notification::make()->danger()->title('Record details could not be loaded')
                    ->body('The Salesforce record may be unavailable or the connection may have lost access. Please try again.')->send();
                $action->halt();
            })
            ->modalSubmitAction(fn ($action) => $this->canEditRecordDefaults() ? $action->label('Save defaults') : false)
            ->modalCancelActionLabel('Close')
            ->action(function (array $data, array $record): void {
                abort_unless(static::canAccess() && $this->canEditRecordDefaults(), 403);
                $this->saveRecordDefaults($record, $data);
            });
    }

    protected function canEditRecordDefaults(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     */
    protected function saveRecordDefaults(array $record, array $data): void
    {
        abort(403);
    }

    /** @return array<Component> */
    protected function recordDetailsSchema(): array
    {
        $fields = [
            TextInput::make('Project_Reference_Number__c')->label('Reference'),
            TextInput::make('Name')->label('Project Name')->columnSpanFull(),
            TextInput::make('StageName')->label('Stage'),
            TextInput::make('CreatedDate')->label('Created'),
            TextInput::make('CustomerName')->label('Customer Name'),
            TextInput::make('OwnerName')->label('Owner'),
            TextInput::make('OwnerEmail')->label('Owner Email'),
            TextInput::make('CloseDate')->label('Close Date'),
            TextInput::make('BranchName')->label('Branch'),
        ];

        if (auth()->user()?->can('pricing.view')) {
            $fields[] = TextInput::make('Amount')->label('Amount')->prefix('£');
        }

        $fields[] = Textarea::make('Description')->label('Description')->columnSpanFull();

        return [Grid::make(2)->schema($fields)];
    }

    /** @return array<string, mixed>|null */
    protected function loadRecordDetails(string $id): ?array
    {
        $canViewPrices = auth()->user()?->can('pricing.view') ?? false;
        $record = app(SalesforceService::class)->getOpportunityDetails($id, $canViewPrices);

        if ($record === null) {
            return null;
        }

        $data = Arr::only($record, ['Id', 'Project_Reference_Number__c', 'Name', 'StageName', 'CreatedDate', 'AccountId', 'CloseDate', 'Description']);
        foreach (['CreatedDate', 'CloseDate'] as $field) {
            $data[$field] = filled($record[$field] ?? null) ? Carbon::parse($record[$field])->format('d/m/Y') : null;
        }

        $data['CustomerName'] = $record['Miscellaneous_Customer_Name__c'] ?? data_get($record, 'Account.Name');
        $data['OwnerName'] = data_get($record, 'Owner.Name');
        $data['OwnerEmail'] = str_replace('.invalid', '', (string) data_get($record, 'Owner.Email', ''));
        $data['BranchName'] = data_get($record, 'CEF_Branch__r.Name');

        if ($canViewPrices) {
            $data['Amount'] = isset($record['Amount']) ? number_format((float) $record['Amount'], 2, '.', ',') : null;
        }

        return $data;
    }

    public function canManageSalesforcePush(): bool
    {
        return auth()->user()?->can('salesforce.manage-push') ?? false;
    }

    public function toggleSalesforcePushDisabled(): void
    {
        abort_unless($this->canManageSalesforcePush(), 403);

        $pushControl = app(SalesforcePushControl::class);

        $pushControl->setDisabled(! $pushControl->disabled());

        $this->salesforcePushDisabled = $pushControl->disabled();
    }
}
