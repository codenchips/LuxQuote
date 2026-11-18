<?php

namespace Tests\Feature;

use App\Filament\Pages\SalesforceCustomers;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Permission;
use App\Models\Project;
use App\Models\SalesforceCustomerDefault;
use App\Models\User;
use App\Services\SalesforceService;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesforceCustomerDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private const AccountId = '001000000000001AAA';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function fakeCustomer(): void
    {
        $record = ['Id' => self::AccountId, 'Name' => 'Example customer', 'Type' => 'Contractor', 'CreatedDate' => '2025-10-23T15:29:47.000+0000', 'Website' => 'https://example.com', 'Industry' => 'Lighting', 'Description' => 'Unused description'];
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getOpportunityStageOptions')->andReturn([]);
        $service->shouldReceive('getAccounts')->andReturn(new LengthAwarePaginator(collect([$record])->keyBy('Id'), 1, 25));
        $service->shouldReceive('getAccountDetails')->with(self::AccountId)->andReturn($record);
        $service->shouldReceive('getAccountTypeOptions')->andReturn(['Contractor' => 'Contractor', 'Consultant' => 'Consultant']);
    }

    public function test_manager_can_save_defaults_by_customer_id_without_updating_salesforce_fields_or_existing_projects(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $this->fakeCustomer();
        $existing = Project::factory()->create(['cover_1' => 17, 'cover_2' => 8, 'cover_3' => 2]);
        $component = Livewire::test(SalesforceCustomers::class)
            ->mountTableAction('viewRecord', self::AccountId)
            ->assertActionDataSet(['cover_1' => '5.00', 'cover_2' => '5.00', 'cover_3' => '5.00'])
            ->assertActionDataSet(['CreatedDate' => '23/10/2025'])
            ->assertFormFieldDoesNotExist('Website')
            ->assertFormFieldDoesNotExist('Industry')
            ->assertFormFieldDoesNotExist('Description')
            ->assertFormFieldDisabled('Name')->assertFormFieldDisabled('Type')
            ->assertFormFieldEnabled('cover_1')
            ->assertDontSee('Salesforce Account ID');
        $schema = $component->instance()->getMountedAction()->getSchema(Schema::make($component->instance()));
        $addresses = collect($schema->getComponents())->filter(fn ($component): bool => $component instanceof Section);
        $this->assertCount(2, $addresses);
        foreach ($addresses as $address) {
            $this->assertTrue($address->isCollapsible());
            $this->assertTrue($address->isCollapsed());
        }
        $component->set('mountedActions.0.data.Id', '001000000000002AAA')
            ->set('mountedActions.0.data.Name', 'Forged name')
            ->setActionData(['cover_1' => 7.25, 'cover_2' => 6, 'cover_3' => 0])
            ->callMountedTableAction()->assertHasNoActionErrors()->assertNotified('Customer cover defaults saved');
        $this->assertDatabaseHas('salesforce_customer_defaults', ['salesforce_account_id' => self::AccountId, 'cover_1' => 7.25, 'cover_2' => 6, 'cover_3' => 0]);
        $this->assertDatabaseCount('salesforce_customer_defaults', 1);
        $this->assertSame('17.00', $existing->fresh()->cover_1);
        $this->assertSame(['cover_1' => '5.00', 'cover_2' => '5.00', 'cover_3' => '5.00'], SalesforceCustomerDefault::coverValues('001000000000002AAA'));
        Livewire::test(SalesforceCustomers::class)->mountTableAction('viewRecord', self::AccountId)
            ->assertActionDataSet(['cover_1' => '7.25', 'cover_2' => '6.00', 'cover_3' => '0.00']);
        Http::assertNothingSent();
    }

    public function test_cover_permission_alone_cannot_save_customer_defaults(): void
    {
        $actor = User::factory()->manager()->create();
        $actor->permissionGroup->permissions()->detach(Permission::where('key', 'salesforce.customers.update')->value('id'));
        $this->actingAs($actor);
        $this->fakeCustomer();
        $component = Livewire::test(SalesforceCustomers::class)->mountTableAction('viewRecord', self::AccountId)
            ->assertFormFieldDisabled('cover_1');
        $this->assertNull($component->instance()->getMountedAction()->getModalSubmitAction());
        $component->set('mountedActions.0.data.cover_1', 99)->callMountedTableAction()->assertForbidden();
        $this->assertDatabaseCount('salesforce_customer_defaults', 0);
    }

    public function test_customer_edit_requires_price_visibility_and_defaults_never_leak_without_it(): void
    {
        $actor = User::factory()->manager()->create();
        $actor->permissionGroup->permissions()->detach(Permission::where('key', 'pricing.view')->value('id'));
        $this->actingAs($actor);
        $this->fakeCustomer();
        $component = Livewire::test(SalesforceCustomers::class)->mountTableAction('viewRecord', self::AccountId)->assertDontSee('Default Cover (applied to new projects)');
        $this->assertArrayNotHasKey('cover_1', $component->instance()->mountedActions[0]['data']);
        $component->callMountedTableAction()->assertForbidden();
    }

    public function test_cover_defaults_validate_all_three_percentages(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $this->fakeCustomer();
        Livewire::test(SalesforceCustomers::class)->mountTableAction('viewRecord', self::AccountId)
            ->setActionData(['cover_1' => -1, 'cover_2' => 1000, 'cover_3' => 'abc'])
            ->callMountedTableAction()->assertHasActionErrors(['cover_1', 'cover_2', 'cover_3']);
        $this->assertDatabaseCount('salesforce_customer_defaults', 0);
    }

    public function test_revoked_customer_edit_permission_prevents_saving_an_open_dialog(): void
    {
        $actor = User::factory()->manager()->create();
        $this->actingAs($actor);
        $this->fakeCustomer();
        $component = Livewire::test(SalesforceCustomers::class)->mountTableAction('viewRecord', self::AccountId);
        $actor->permissionGroup->permissions()->detach(Permission::where('key', 'salesforce.customers.update')->value('id'));
        $actor->unsetRelation('permissionGroup');
        $component->callMountedTableAction()->assertForbidden();
        $this->assertDatabaseCount('salesforce_customer_defaults', 0);
    }

    /** @return array<string, array{bool}> */
    public static function customerDefaults(): array
    {
        return ['initial defaults' => [false], 'saved defaults' => [true]];
    }

    #[DataProvider('customerDefaults')]
    public function test_new_projects_use_verified_customer_defaults_even_when_pending_data_names_another_account(bool $hasSavedDefaults): void
    {
        $actor = User::factory()->admin()->create();
        $this->actingAs($actor);
        if ($hasSavedDefaults) {
            SalesforceCustomerDefault::factory()->create(['salesforce_account_id' => self::AccountId, 'cover_1' => 8, 'cover_2' => 4, 'cover_3' => 2]);
        }
        SalesforceCustomerDefault::factory()->create(['salesforce_account_id' => '001000000000002AAA', 'cover_1' => 99]);
        $opportunity = ['Id' => '006000000000001AAA', 'Name' => 'NEW CUSTOMER PROJECT', 'Project_Reference_Number__c' => 'CUSTOMER-001', 'AccountId' => self::AccountId];
        $this->mock(SalesforceService::class)->shouldReceive('getOpportunityById')->with($opportunity['Id'])->andReturn($opportunity);
        Livewire::test(ListProjects::class)->mountAction('create')->setActionData([
            'salesforce_project' => true, 'salesforce_id' => $opportunity['Id'],
            'salesforce_reference_id' => $opportunity['Id'],
            'salesforce_pending_data' => json_encode(array_replace($opportunity, ['AccountId' => '001000000000002AAA'])),
            'name' => $opportunity['Name'], 'reference_number' => $opportunity['Project_Reference_Number__c'],
            'customer_name' => 'Customer', 'created_by_email' => $actor->email, 'date' => now()->toDateString(),
        ])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseHas('projects', ['salesforce_id' => $opportunity['Id'], 'has_cover' => true, 'cover_direction' => 'added', 'cover_1' => $hasSavedDefaults ? 8 : 5, 'cover_2' => $hasSavedDefaults ? 4 : 5, 'cover_3' => $hasSavedDefaults ? 2 : 5]);
    }

    public function test_account_types_use_active_salesforce_picklist_values_and_cache_metadata(): void
    {
        config(['services.salesforce.auth_method' => 'client_credentials', 'services.salesforce.url' => 'https://example.my.salesforce.com', 'services.salesforce.client_id' => 'test-client', 'services.salesforce.client_secret' => 'test-secret']);
        Http::fake([
            '*/services/oauth2/token' => Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com']),
            '*/sobjects/Account/describe' => Http::response(['fields' => [['name' => 'Type', 'picklistValues' => [
                ['active' => true, 'value' => 'Contractor', 'label' => 'Contractor'],
                ['active' => true, 'value' => 'Consultant', 'label' => 'Consultant'],
                ['active' => false, 'value' => 'Retired', 'label' => 'Retired'],
            ]]]]),
        ]);
        $service = app(SalesforceService::class);
        $this->assertSame(['Contractor' => 'Contractor', 'Consultant' => 'Consultant'], $service->getAccountTypeOptions());
        $service->getAccountTypeOptions();
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sobjects/Account/describe'));
    }

    public function test_customer_edit_permission_is_initially_only_in_admin_and_manager_groups(): void
    {
        User::factory()->manager()->create();
        $this->assertSame(['admin', 'manager'], Permission::where('key', 'salesforce.customers.update')->firstOrFail()->groups()->orderBy('slug')->pluck('slug')->all());
    }
}
