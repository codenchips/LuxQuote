<?php

namespace App\Filament\Pages;

use App\Models\SalesforceCustomerDefault;
use App\Services\SalesforceService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SalesforceCustomers extends Salesforce
{
    protected static ?string $title = 'Salesforce Customers';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $slug = 'salesforce/customers';

    protected string $view = 'filament.pages.salesforce-customers';

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
                    return app(SalesforceService::class)->getAccounts($page, $recordsPerPage, $search, $sortColumn, $sortDirection, $filters['type']['values'] ?? []);
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
                TextColumn::make('Phone')->label('Phone')->placeholder('—'),
                TextColumn::make('BillingCity')->label('Town / City')->sortable()->placeholder('—'),
                TextColumn::make('BillingPostalCode')->label('Postcode')->sortable()->placeholder('—'),
                TextColumn::make('BillingCountry')->label('Country')->sortable()->placeholder('—')->toggleable(),
                TextColumn::make('Website')->label('Website')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('CreatedDate')->label('Created')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([$this->recordDetailsAction()])
            ->recordAction('viewRecord')
            ->searchable()
            ->defaultSort('Name', 'asc')
            ->filters([
                SelectFilter::make('type')->label('Customer Type')->multiple()
                    ->options(fn (): array => app(SalesforceService::class)->getAccountTypeOptions()),
            ])
            ->persistFiltersInSession()
            ->emptyStateHeading('No customers found')
            ->paginated([10, 25, 50]);
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
        $values = Validator::make(Arr::only($data, ['cover_1', 'cover_2', 'cover_3']), [
            'cover_1' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'cover_2' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'cover_3' => ['required', 'numeric', 'min:0', 'max:999.99'],
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
                ->columns(3)
                ->schema(collect((auth()->user()?->can('pricing.view') ?? false) ? ['cover_1' => 'Cover 1', 'cover_2' => 'Cover 2', 'cover_3' => 'Cover 3'] : [])
                    ->map(fn (string $label, string $field): TextInput => TextInput::make($field)
                        ->label($label)->numeric()->required()->suffix('%')->minValue(0)->maxValue(999.99)
                        ->extraInputAttributes(['step' => '0.01'])
                        ->disabled(fn (): bool => ! $this->canEditRecordDefaults()))->all()),
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
        $record = app(SalesforceService::class)->getAccountDetails($id);

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
