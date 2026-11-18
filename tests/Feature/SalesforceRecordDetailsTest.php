<?php

namespace Tests\Feature;

use App\Filament\Pages\Salesforce;
use App\Filament\Pages\SalesforceCustomers;
use App\Models\Permission;
use App\Models\User;
use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SalesforceRecordDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'services.salesforce.auth_method' => 'client_credentials',
            'services.salesforce.client_id' => 'test-client-id',
            'services.salesforce.client_secret' => 'test-client-secret',
            'services.salesforce.url' => 'https://example.my.salesforce.com',
            'services.salesforce.retry_attempts' => 1,
        ]);
    }

    public function test_customer_row_opens_read_only_details_by_id_even_when_names_match(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $first = ['Id' => '001000000000001AAA', 'Name' => 'Same name'];
        $second = ['Id' => '001000000000002AAA', 'Name' => 'Same name'];
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getOpportunityStageOptions')->andReturn([]);
        $service->shouldReceive('getAccountTypeOptions')->andReturn([]);
        $service->shouldReceive('getAccounts')->andReturn($this->paginator([$first, $second]));
        $service->shouldReceive('getAccountDetails')->once()->with($second['Id'])->andReturn($second + [
            'Phone' => '01234 567890', 'BillingStreet' => '12 High Street', 'ShippingCity' => 'London',
            'Description' => 'Customer details', 'Owner' => ['Name' => 'Owner', 'Email' => 'owner@example.com.invalid'],
            'UnrequestedSensitiveField' => 'Do not expose this',
        ]);

        $component = Livewire::test(SalesforceCustomers::class)->assertSee('Salesforce Customers')
            ->mountTableAction('viewRecord', $second['Id'])
            ->assertActionDataSet(['Id' => $second['Id'], 'Name' => 'Same name', 'Phone' => '01234 567890', 'BillingStreet' => '12 High Street', 'OwnerEmail' => 'owner@example.com'])
            ->assertFormFieldDisabled('Name')->assertFormFieldDisabled('Phone')->assertFormFieldDisabled('BillingStreet');

        $action = $component->instance()->getMountedAction();
        $this->assertSame('Save defaults', $action->getModalSubmitAction()->getLabel());
        $this->assertSame('Close', $action->getModalCancelAction()->getLabel());
        $this->assertSame('viewRecord', $component->instance()->getTable()->getRecordAction($second));
        $this->assertArrayNotHasKey('UnrequestedSensitiveField', $component->instance()->mountedActions[0]['data']);
        Http::assertNothingSent();
    }

    public function test_project_row_opens_read_only_details_with_permission_guarded_amounts(): void
    {
        $record = ['Id' => '006000000000001AAA', 'Name' => 'Salesforce project', 'Project_Reference_Number__c' => '22021'];
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getOpportunityStageOptions')->andReturn([]);
        $service->shouldReceive('getAccountTypeOptions')->andReturn([]);
        $service->shouldReceive('getOpportunities')->andReturn($this->paginator([$record]));
        $service->shouldReceive('getOpportunityDetails')->with($record['Id'], true)->andReturn($record + [
            'CreatedDate' => '2026-02-26T15:49:50.000+0000', 'CloseDate' => '2026-03-08',
            'StageName' => 'Open', 'Amount' => 1234.56, 'AccountId' => '001000000000001AAA',
            'Account' => ['Name' => 'Customer'], 'Owner' => ['Name' => 'Engineer', 'Email' => 'engineer@example.com'],
        ]);
        $this->actingAs(User::factory()->manager()->create());
        $component = Livewire::test(Salesforce::class)->assertSee('Salesforce Projects')
            ->mountTableAction('viewRecord', $record['Id'])
            ->assertActionDataSet(['Id' => $record['Id'], 'Name' => 'Salesforce project', 'Amount' => '1,234.56', 'CreatedDate' => '26/02/2026', 'CloseDate' => '08/03/2026', 'CustomerName' => 'Customer', 'AccountId' => '001000000000001AAA'])
            ->assertFormFieldDisabled('Name')->assertFormFieldDisabled('Amount');
        $this->assertNull($component->instance()->getMountedAction()->getModalSubmitAction());
        $this->assertSame('viewRecord', $component->instance()->getTable()->getRecordAction($record));
    }

    public function test_project_details_never_include_amounts_without_price_visibility(): void
    {
        $actor = User::factory()->technical()->create();
        $actor->permissionGroup->permissions()->syncWithoutDetaching([Permission::where('key', 'salesforce.view')->value('id')]);
        $this->actingAs($actor);
        $record = ['Id' => '006000000000001AAA', 'Name' => 'No price visibility'];
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getOpportunityStageOptions')->andReturn([]);
        $service->shouldReceive('getAccountTypeOptions')->andReturn([]);
        $service->shouldReceive('getOpportunities')->andReturn($this->paginator([$record]));
        $service->shouldReceive('getOpportunityDetails')->once()->with($record['Id'], false)->andReturn($record + ['Amount' => 99999.99]);
        $component = Livewire::test(Salesforce::class)->mountTableAction('viewRecord', $record['Id']);
        $this->assertArrayNotHasKey('Amount', $component->instance()->mountedActions[0]['data']);
        $component->assertDontSee('99,999.99');
    }

    public function test_forged_submit_cannot_edit_salesforce_records(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $record = ['Id' => '001000000000001AAA', 'Name' => 'Original name'];
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getOpportunityStageOptions')->andReturn([]);
        $service->shouldReceive('getAccountTypeOptions')->andReturn([]);
        $service->shouldReceive('getAccounts')->andReturn($this->paginator([$record]));
        $service->shouldReceive('getAccountDetails')->andReturn($record);
        Livewire::test(SalesforceCustomers::class)->mountTableAction('viewRecord', $record['Id'])
            ->set('mountedActions.0.data.Name', 'Forged name')->callMountedTableAction()->assertHasNoActionErrors();
        Http::assertNothingSent();
    }

    public function test_unavailable_records_show_a_notification(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $record = ['Id' => '001000000000001AAA', 'Name' => 'Unavailable record'];
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getOpportunityStageOptions')->andReturn([]);
        $service->shouldReceive('getAccountTypeOptions')->andReturn([]);
        $service->shouldReceive('getAccounts')->andReturn($this->paginator([$record]));
        $service->shouldReceive('getAccountDetails')->andReturnNull();
        Livewire::test(SalesforceCustomers::class)->mountTableAction('viewRecord', $record['Id'])
            ->assertNotified('Record details could not be loaded');
    }

    public function test_service_rejects_invalid_or_wrong_object_ids_without_requesting_salesforce(): void
    {
        $service = app(SalesforceService::class);
        $this->assertNull($service->getAccountDetails("001000000000001AAA' OR Name != ''"));
        $this->assertNull($service->getAccountDetails('006000000000001AAA'));
        $this->assertNull($service->getOpportunityDetails('001000000000001AAA'));
        Http::assertNothingSent();
    }

    public function test_service_loads_records_by_id_and_omits_price_fields_from_unpriced_queries(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com', 'expires_in' => 3600]);
            }

            $query = (string) ($request->data()['q'] ?? '');

            if (str_contains($query, 'COUNT()')) {
                return Http::response(['totalSize' => 1]);
            }

            $id = str_contains($query, 'FROM Account') ? '001000000000001AAA' : '006000000000001AAA';

            return Http::response(['records' => [['Id' => $id, 'Name' => 'Fetched by ID']]]);
        });
        $service = app(SalesforceService::class);
        $this->assertSame('001000000000001AAA', $service->getAccountDetails('001000000000001AAA')['Id']);
        $this->assertSame('001000000000001AAA', $service->getAccountDetails('001000000000001')['Id']);
        $this->assertSame('006000000000001AAA', $service->getOpportunityDetails('006000000000001AAA')['Id']);
        $this->assertSame(['006000000000001AAA'], array_keys($service->getOpportunities(fields: ['Id', 'Name'])->items()));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "FROM Account WHERE Id = '001000000000001AAA' LIMIT 1"));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "FROM Opportunity WHERE Id = '006000000000001AAA' LIMIT 1"));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), 'Amount'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET' && ! str_contains($request->url(), '/services/oauth2/token'));
    }

    public function test_optional_field_permission_failures_fall_back_to_table_fields(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com', 'expires_in' => 3600]);
            }

            $query = (string) ($request->data()['q'] ?? '');

            if (str_contains($query, 'Description')) {
                return Http::response([['errorCode' => 'INVALID_FIELD']], 400);
            }

            $id = str_contains($query, 'FROM Account') ? '001000000000001AAA' : '006000000000001AAA';

            return Http::response(['records' => [['Id' => $id, 'Name' => 'Basic record', 'Owner' => ['Name' => 'Engineer', 'Email' => 'engineer@example.com']]]]);
        });
        $service = app(SalesforceService::class);
        $this->assertSame('Basic record', $service->getAccountDetails('001000000000001AAA')['Name']);
        $this->assertSame('Engineer', $service->getOpportunityDetails('006000000000001AAA')['Owner']['Name']);
    }

    public function test_revoked_permission_prevents_loading_record_details(): void
    {
        $actor = User::factory()->manager()->create();
        $this->actingAs($actor);
        $record = ['Id' => '001000000000001AAA', 'Name' => 'Restricted record'];
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getOpportunityStageOptions')->andReturn([]);
        $service->shouldReceive('getAccountTypeOptions')->andReturn([]);
        $service->shouldReceive('getAccounts')->andReturn($this->paginator([$record]));
        $service->shouldNotReceive('getAccountDetails');
        $component = Livewire::test(SalesforceCustomers::class);
        $actor->permissionGroup->permissions()->detach(Permission::where('key', 'salesforce.view')->value('id'));
        $component->mountTableAction('viewRecord', $record['Id'])->assertForbidden();
    }

    /** @param array<int, array<string, mixed>> $records */
    private function paginator(array $records): LengthAwarePaginator
    {
        return new LengthAwarePaginator(collect($records)->keyBy('Id'), count($records), 10, 1);
    }
}
