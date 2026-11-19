<?php

namespace Tests\Feature;

use App\Enums\ProjectVisibility;
use App\Filament\Pages\SalesforceCustomers;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Project;
use App\Models\User;
use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CustomerPastProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const AccountId = '001000000000001AAA';

    private const OtherAccountId = '001000000000002AAA';

    private const OpportunityId = '006000000000001AAA';

    private function fakeCustomer(): MockInterface
    {
        $accounts = collect([
            ['Id' => self::AccountId, 'Name' => 'Customer One', 'PastProjectsCount' => 17],
            ['Id' => self::OtherAccountId, 'Name' => 'Customer Two', 'PastProjectsCount' => 0],
        ])->keyBy('Id');
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getAccounts')->andReturn(new LengthAwarePaginator($accounts, 2, 25));
        $service->shouldReceive('getAccountDetails')->andReturnUsing(fn (string $id): ?array => $accounts->get($id));
        $service->shouldReceive('getAccountTypeOptions')->andReturn([]);
        $service->shouldReceive('getAccountOwnerOptions')->andReturn([]);

        return $service;
    }

    /** @return array<string, mixed> */
    private function opportunity(string $id = self::OpportunityId, string $name = 'Closed Salesforce project'): array
    {
        return ['Id' => $id, 'Name' => $name, 'Project_Reference_Number__c' => '20748', 'StageName' => 'Closed Won', 'CreatedDate' => '2026-01-19T15:29:47.000+0000'];
    }

    public function test_customers_table_has_a_past_projects_button_and_no_phone_or_address_columns(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $this->fakeCustomer()->shouldNotReceive('getCustomerProjects');
        $component = Livewire::test(SalesforceCustomers::class)
            ->assertTableColumnExists('past_projects')
            ->assertTableColumnDoesNotExist('Phone')
            ->assertTableColumnDoesNotExist('BillingCity')
            ->assertTableColumnDoesNotExist('BillingPostalCode')
            ->assertTableColumnDoesNotExist('BillingCountry')
            ->assertDontSee('View projects')
            ->assertTableColumnStateSet('past_projects', 17, self::AccountId)
            ->assertTableColumnStateSet('past_projects', 0, self::OtherAccountId)
            ->assertTableActionHasIcon('viewRecord', 'heroicon-o-pencil-square', self::AccountId)
            ->mountTableAction('viewRecord', self::AccountId);
        $html = $component->getMountedActionModalHtml();
        $this->assertStringNotContainsString('Past Projects', $html);
        $this->assertStringContainsString('Billing address', $html);
        $this->assertStringContainsString('Phone', $html);
        $component->call('loadCustomerPastProjects')->assertForbidden();
    }

    public function test_history_loads_only_on_request_and_matches_luxquote_by_id(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $project = Project::factory()->for($admin)->create(['salesforce_id' => self::OpportunityId, 'reference_number' => 'DIFFERENT-REF']);
        Project::factory()->for($admin)->create(['reference_number' => '20748', 'salesforce_id' => '006000000000099AAA']);
        $service = $this->fakeCustomer();
        $service->shouldReceive('getCustomerProjects')->once()->with(self::AccountId, 1)
            ->andReturn(new LengthAwarePaginator([$this->opportunity(), $this->opportunity('006000000000002AAA', 'Salesforce only project')], 2, 5));
        $component = Livewire::test(SalesforceCustomers::class)->mountTableAction('pastProjects', self::AccountId)
            ->assertSet('pastProjectsLoaded', false)->assertSet('pastProjects', []);
        $this->assertStringContainsString('Past Projects', $component->getMountedActionModalHtml());
        $component->call('loadCustomerPastProjects')->assertSet('pastProjectsLoaded', true);
        $html = $component->getMountedActionModalHtml();
        foreach (['Closed Salesforce project', 'Closed Won', '19/01/2026', 'Project Date', 'Status', 'Open in LuxQuote', ProjectResource::getUrl('view', ['record' => $project])] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $rows = $component->get('pastProjects');
        $this->assertNotNull($rows[0]['url']);
        $this->assertNull($rows[1]['url']);
        $this->assertStringNotContainsString(self::OpportunityId, strip_tags($html));
        $this->assertStringNotContainsString(self::AccountId, strip_tags($html));
    }

    public function test_history_dialog_starts_loading_once_when_opened_in_the_browser(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->fakeCustomer()->shouldNotReceive('getCustomerProjects');
        $component = Livewire::test(SalesforceCustomers::class)->mountTableAction('pastProjects', self::AccountId);
        $document = new \DOMDocument;
        @$document->loadHTML($component->getMountedActionModalHtml());
        $section = null;
        foreach ($document->getElementsByTagName('div') as $element) {
            if (str_starts_with($element->getAttribute('wire:key'), 'customer-projects-list-')) {
                $section = $element;
                break;
            }
        }
        $this->assertNotNull($section, 'Past Projects list was not rendered in its dialog.');
        $process = new Process(['node', 'tests/Browser/Components/customer-past-projects-checks.mjs'], base_path(), [
            'CUSTOMER_PANEL_HTML' => base64_encode($document->saveHTML($section)),
        ]);
        $process->setTimeout(30)->run();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }

    public function test_history_paginates_five_at_a_time_and_resets_for_a_different_customer(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $service = $this->fakeCustomer();
        $service->shouldReceive('getCustomerProjects')->with(self::AccountId, 1)->once()->andReturn(new LengthAwarePaginator(array_fill(0, 5, $this->opportunity()), 6, 5, 1));
        $service->shouldReceive('getCustomerProjects')->with(self::AccountId, 2)->once()->andReturn(new LengthAwarePaginator([$this->opportunity('006000000000006AAA', 'Sixth project')], 6, 5, 2));
        $component = Livewire::test(SalesforceCustomers::class)->mountTableAction('pastProjects', self::AccountId)
            ->call('loadCustomerPastProjects')->assertSet('pastProjectsTotal', 6)->assertSet('pastProjectsPage', 1);
        $this->assertCount(5, $component->get('pastProjects'));
        $component->call('loadCustomerPastProjects', 2)->assertSet('pastProjectsPage', 2);
        $this->assertStringContainsString('Sixth project', $component->getMountedActionModalHtml());
        $this->assertCount(1, $component->get('pastProjects'));
        $component->call('unmountAction')->mountTableAction('pastProjects', self::OtherAccountId)
            ->assertSet('pastProjectsLoaded', false)->assertSet('pastProjects', [])->assertSet('pastProjectsPage', 1);
    }

    public function test_history_reports_salesforce_failures_and_can_retry(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $service = $this->fakeCustomer();
        $service->shouldReceive('getCustomerProjects')->once()->andThrow(new RuntimeException('Salesforce unavailable'));
        $service->shouldReceive('getCustomerProjects')->once()->andReturn(new LengthAwarePaginator([], 0, 5));
        $component = Livewire::test(SalesforceCustomers::class)->mountTableAction('pastProjects', self::AccountId)
            ->call('loadCustomerPastProjects');
        $this->assertStringContainsString('Projects could not be loaded from Salesforce.', $component->getMountedActionModalHtml());
        $this->assertStringContainsString('Retry', $component->getMountedActionModalHtml());
        $component->call('loadCustomerPastProjects')->assertSet('pastProjectsLoaded', true)->assertSet('pastProjectsError', null);
        $this->assertStringContainsString('No past projects found', $component->getMountedActionModalHtml());
    }

    public function test_private_luxquote_projects_are_not_linked_or_disclosed(): void
    {
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager);
        $private = Project::factory()->create(['salesforce_id' => self::OpportunityId, 'visibility' => ProjectVisibility::Private]);
        $service = $this->fakeCustomer();
        $service->shouldReceive('getCustomerProjects')->andReturn(new LengthAwarePaginator([$this->opportunity()], 1, 5));
        $component = Livewire::test(SalesforceCustomers::class)->mountTableAction('pastProjects', self::AccountId)->call('loadCustomerPastProjects');
        $this->assertNull($component->get('pastProjects')[0]['url']);
        $component->assertDontSee(ProjectResource::getUrl('view', ['record' => $private]), escape: false)->assertDontSee('Open in LuxQuote');
    }

    public function test_salesforce_access_alone_can_view_history_but_cannot_link_luxquote_projects(): void
    {
        $group = PermissionGroup::create(['name' => 'Salesforce viewer', 'slug' => 'past-projects-viewer', 'is_system' => false]);
        $group->permissions()->attach(Permission::where('key', 'salesforce.view')->firstOrFail());
        $user = User::factory()->create(['permission_group_id' => $group->id]);
        $this->actingAs($user);
        Project::factory()->for($user)->create(['salesforce_id' => self::OpportunityId]);
        $service = $this->fakeCustomer();
        $service->shouldReceive('getCustomerProjects')->andReturn(new LengthAwarePaginator([$this->opportunity()], 1, 5));
        Livewire::test(SalesforceCustomers::class)->mountTableAction('pastProjects', self::AccountId)->call('loadCustomerPastProjects')
            ->assertSet('pastProjectsLoaded', true)->assertDontSee('Open in LuxQuote');
    }

    public function test_history_requires_an_open_past_projects_dialog_and_current_salesforce_permission(): void
    {
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager);
        $this->fakeCustomer()->shouldNotReceive('getCustomerProjects');
        Livewire::test(SalesforceCustomers::class)->call('loadCustomerPastProjects')->assertForbidden();
        $component = Livewire::test(SalesforceCustomers::class)->mountTableAction('pastProjects', self::AccountId);
        $manager->permissionGroup->permissions()->detach(Permission::where('key', 'salesforce.view')->value('id'));
        $component->call('loadCustomerPastProjects')->assertForbidden();
    }

    public function test_salesforce_query_is_scoped_to_account_includes_closed_projects_and_supports_cursors(): void
    {
        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com']);
            }
            if (str_contains($request->url(), '/query/next-customer-projects')) {
                return Http::response(['records' => [$this->opportunity('006000000000006AAA', 'Sixth project')], 'done' => true]);
            }

            return Http::response(['totalSize' => 6, 'records' => array_map(fn (int $index): array => $this->opportunity('006'.str_pad((string) $index, 12, '0', STR_PAD_LEFT).'AAA'), range(1, 5)), 'nextRecordsUrl' => '/services/data/v65.0/query/next-customer-projects', 'done' => false]);
        });
        $service = app(SalesforceService::class);
        $first = $service->getCustomerProjects(self::AccountId);
        $this->assertCount(5, $first->items());
        $this->assertSame(6, $first->total());
        $second = $service->getCustomerProjects(self::AccountId, 2);
        $this->assertSame('Sixth project', collect($second->items())->sole()['Name']);
        Http::assertSent(function (Request $request): bool {
            $query = (string) ($request->data()['q'] ?? '');

            return str_contains($query, "WHERE AccountId = '".self::AccountId."'")
                && str_contains($query, 'ORDER BY CreatedDate DESC, Id DESC')
                && ! str_contains($query, 'IsClosed = false') && ! str_contains($query, 'IsWon = false')
                && ! str_contains($query, 'Amount');
        });
    }

    public function test_invalid_customer_ids_are_rejected_before_salesforce_requests(): void
    {
        Http::preventStrayRequests();
        $this->expectException(RuntimeException::class);
        app(SalesforceService::class)->getCustomerProjects("001' OR Name != ''");
    }
}
