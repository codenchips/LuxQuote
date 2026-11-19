<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\SalesforceCustomerDefault;
use App\Services\SalesforceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Throwable;

class SalesforceCustomers extends Salesforce
{
    protected static ?string $title = 'Salesforce Customers';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $slug = 'salesforce/customers';

    protected string $view = 'filament.pages.salesforce-customers';

    /** @var array<string, array<string, mixed>> */
    protected array $resolvedCustomerDetails = [];

    protected function resolveTableRecord(?string $key): Model|array|null
    {
        abort_unless(static::canAccess(), 403);
        if ($key === null || ! preg_match('/^001[A-Za-z0-9]{12}(?:[A-Za-z0-9]{3})?$/', $key)) {
            return null;
        }
        $record = parent::resolveTableRecord($key);
        if ($record !== null) {
            return $record;
        }

        try {
            $record = app(SalesforceService::class)->getAccountDetails($key);
            if ($record !== null) {
                $this->resolvedCustomerDetails[$key] = $record;
            }

            return $record;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Customer could not be loaded')->send();

            return null;
        }
    }

    /** @var array<int, array{name: string, reference: string, stage: string, date: string, url: string|null}> */
    #[Locked]
    public array $pastProjects = [];

    #[Locked]
    public ?string $pastProjectsAccountId = null;

    #[Locked]
    public int $pastProjectsPage = 1;

    #[Locked]
    public int $pastProjectsTotal = 0;

    #[Locked]
    public bool $pastProjectsLoaded = false;

    #[Locked]
    public ?string $pastProjectsError = null;

    public function loadCustomerPastProjects(int $page = 1): void
    {
        abort_unless(static::canAccess(), 403);
        $action = $this->getMountedAction();
        abort_unless($action?->getName() === 'pastProjects', 403);
        $record = $action->getRecord();
        $accountId = is_array($record) ? (string) ($record['Id'] ?? '') : '';
        abort_unless($accountId !== '' && $accountId === $this->pastProjectsAccountId, 403);
        $page = max(1, min($page, $this->pastProjectsLoaded ? max(1, (int) ceil($this->pastProjectsTotal / 5)) : 1));
        $this->pastProjects = [];
        $this->pastProjectsError = null;
        $this->pastProjectsLoaded = false;

        try {
            $projects = app(SalesforceService::class)->getCustomerProjects($accountId, $page);
            $matches = ProjectResource::canViewAny()
                ? ProjectResource::getEloquentQuery()->whereIn('salesforce_id', collect($projects->items())->pluck('Id'))->get(['id', 'salesforce_id', 'reference_number'])->keyBy('salesforce_id')
                : collect();
            $this->pastProjects = collect($projects->items())->map(function (array $project) use ($matches): array {
                $localProject = $matches->get($project['Id']);

                return [
                    'name' => (string) ($project['Name'] ?? 'Unnamed project'),
                    'reference' => (string) ($project['Project_Reference_Number__c'] ?? '—'),
                    'stage' => (string) ($project['StageName'] ?? '—'),
                    'date' => filled($project['CreatedDate'] ?? null) ? Carbon::parse($project['CreatedDate'])->format('d/m/Y') : '—',
                    'url' => $localProject !== null ? ProjectResource::getUrl('view', ['record' => $localProject]) : null,
                ];
            })->values()->all();
            $this->pastProjectsPage = $page;
            $this->pastProjectsTotal = $projects->total();
            $this->pastProjectsLoaded = true;
        } catch (Throwable $exception) {
            report($exception);
            $this->pastProjectsError = 'Projects could not be loaded from Salesforce. Please try again.';
        }
    }

    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [self::getRouteName(), Salesforce::getRouteName()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $sortColumn, ?string $sortDirection, ?string $search, ?array $filters, int $page, int $recordsPerPage): LengthAwarePaginator {
                abort_unless(static::canAccess(), 403);

                try {
                    return app(SalesforceService::class)->getAccounts($page, $recordsPerPage, $search, $sortColumn, $sortDirection, $filters['type']['values'] ?? [], $filters['owner']['values'] ?? [], $filters['customer']['values'] ?? []);
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title('Customers could not be loaded')
                        ->body('Salesforce is unavailable or the connection cannot read Accounts. Please try again.')->send();

                    return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
                }
            })
            ->columns([
                TextColumn::make('Name')->label('Customer Name')->searchable()->sortable()->wrap(),
                TextColumn::make('Type')->label('Type')->sortable()->placeholder('—'),
                TextColumn::make('Owner.Name')->label('Owner')->placeholder('—')->sortable()->toggleable(),
                ViewColumn::make('past_projects')->label('Past projects')
                    ->state(fn (array $record): ?int => isset($record['PastProjectsCount']) ? (int) $record['PastProjectsCount'] : null)
                    ->view('filament.pages.customer-past-projects-button')
                    ->action($this->pastProjectsAction()),
                TextColumn::make('Website')->label('Website')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('CreatedDate')->label('Created')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([$this->recordDetailsAction()->icon('heroicon-o-pencil-square')->label('Edit details')->tooltip('Edit details')])
            ->recordAction('viewRecord')
            ->searchable()
            ->defaultSort('Name', 'asc')
            ->filters([
                SelectFilter::make('customer')->label('Customer Name')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => app(SalesforceService::class)->getAccountNameOptions($search))
                    ->getOptionLabelsUsing(fn (array $values): array => $values === [] ? [] : app(SalesforceService::class)->getAccountNameOptions(ids: $values)),
                SelectFilter::make('type')->label('Customer Type')->multiple()
                    ->options(fn (): array => app(SalesforceService::class)->getAccountTypeOptions()),
                SelectFilter::make('owner')->label('Owner')->multiple()->searchable()
                    ->options(fn (): array => app(SalesforceService::class)->getAccountOwnerOptions()),
            ])
            ->persistFiltersInSession()
            ->emptyStateHeading('No customers found')
            ->paginated([10, 25, 50]);
    }

    protected function pastProjectsAction(): Action
    {
        return Action::make('pastProjects')
            ->authorize(fn (): bool => static::canAccess())
            ->modalHeading('Past Projects')
            ->modalDescription(fn (array $record): string => (string) ($record['Name'] ?? ''))
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->mountUsing(function (array $record): void {
                abort_unless(static::canAccess(), 403);
                $this->pastProjectsAccountId = (string) ($record['Id'] ?? '');
                $this->pastProjects = [];
                $this->pastProjectsPage = 1;
                $this->pastProjectsTotal = 0;
                $this->pastProjectsLoaded = false;
                $this->pastProjectsError = null;
            })
            ->schema([View::make('filament.pages.customer-past-projects')]);
    }

    protected function canEditRecordDefaults(): bool
    {
        return static::canAccess()
            && (auth()->user()?->can('pricing.view') ?? false)
            && (auth()->user()?->can('salesforce.customers.update') ?? false);
    }

    protected function saveRecordDefaults(array $record, array $data): void
    {
        abort_unless($this->canEditRecordDefaults(), 403);
        $account = app(SalesforceService::class)->getAccountDetails((string) ($record['Id'] ?? ''));
        abort_unless($account !== null, 404);
        $values = Validator::make(Arr::only($data, ['cover_1', 'cover_2', 'cover_3', 'cover_direction']), [
            'cover_1' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'cover_2' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'cover_3' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'cover_direction' => ['required', 'in:added,deducted'],
        ])->validate();
        SalesforceCustomerDefault::updateOrCreate(['salesforce_account_id' => $account['Id']], $values);
        Notification::make()->success()->title('Customer cover defaults saved')->send();
    }

    /** @return array<Component> */
    protected function recordDetailsSchema(): array
    {
        return [
            Grid::make(2)->disabled()->schema([
                TextInput::make('Name')->label('Customer Name'),
                Select::make('Type')->label('Type')->options(function (Get $get): array {
                    $options = app(SalesforceService::class)->getAccountTypeOptions();
                    $currentType = $get('Type');
                    if (filled($currentType) && ! array_key_exists($currentType, $options)) {
                        $options[$currentType] = $currentType;
                    }

                    return $options;
                })->disabled(),
                TextInput::make('Phone')->label('Phone'),
                TextInput::make('CreatedDate')->label('Created'),
                TextInput::make('OwnerName')->label('Owner'),
                TextInput::make('OwnerEmail')->label('Owner Email'),
            ]),
            Fieldset::make('Default Cover (applied to new projects)')
                ->visible(fn (): bool => auth()->user()?->can('pricing.view') ?? false)
                ->columns(1)
                ->schema(fn (): array => ! (auth()->user()?->can('pricing.view') ?? false) ? [] : [
                    Flex::make([
                        ...collect(['cover_1' => 'Cover 1', 'cover_2' => 'Cover 2', 'cover_3' => 'Cover 3'])
                            ->map(fn (string $label, string $field): TextInput => TextInput::make($field)
                                ->label($label)->numeric()->required()->suffix('%')->minValue(0)->maxValue(999.99)
                                ->extraInputAttributes(['step' => '0.01'])
                                ->disabled(fn (): bool => ! $this->canEditRecordDefaults()))->all(),
                        ToggleButtons::make('cover_direction')
                            ->label('Cover Direction')->hiddenLabel()
                            ->options(['added' => 'Added', 'deducted' => 'Deducted'])
                            ->default('added')->required()->in(['added', 'deducted'])->grouped()->grow(false)
                            ->disabled(fn (): bool => ! $this->canEditRecordDefaults()),
                    ])->from('md')->verticallyAlignEnd(),
                ]),
            Section::make('Billing address')->collapsible()->collapsed()->disabled()->columns(2)->schema([
                Textarea::make('BillingStreet')->label('Street')->rows(2)->columnSpanFull(),
                TextInput::make('BillingCity')->label('Town / City'),
                TextInput::make('BillingState')->label('County / State'),
                TextInput::make('BillingPostalCode')->label('Postcode'),
                TextInput::make('BillingCountry')->label('Country'),
            ]),
            Section::make('Shipping address')->collapsible()->collapsed()->disabled()->columns(2)->schema([
                Textarea::make('ShippingStreet')->label('Street')->rows(2)->columnSpanFull(),
                TextInput::make('ShippingCity')->label('Town / City'),
                TextInput::make('ShippingState')->label('County / State'),
                TextInput::make('ShippingPostalCode')->label('Postcode'),
                TextInput::make('ShippingCountry')->label('Country'),
            ]),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function loadRecordDetails(string $id): ?array
    {
        $record = $this->resolvedCustomerDetails[$id] ?? app(SalesforceService::class)->getAccountDetails($id);

        if ($record === null) {
            return null;
        }

        return Arr::only($record, [
            'Id', 'Name', 'Type', 'Phone',
            'BillingStreet', 'BillingCity', 'BillingState', 'BillingPostalCode', 'BillingCountry',
            'ShippingStreet', 'ShippingCity', 'ShippingState', 'ShippingPostalCode', 'ShippingCountry',
        ]) + [
            ...(auth()->user()?->can('pricing.view') ? SalesforceCustomerDefault::coverValues($record['Id']) : []),
            'CreatedDate' => filled($record['CreatedDate'] ?? null) ? Carbon::parse($record['CreatedDate'])->format('d/m/Y') : null,
            'OwnerName' => data_get($record, 'Owner.Name'),
            'OwnerEmail' => str_replace('.invalid', '', (string) data_get($record, 'Owner.Email', '')),
        ];
    }
}
