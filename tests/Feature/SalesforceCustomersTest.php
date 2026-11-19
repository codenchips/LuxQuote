<?php

namespace Tests\Feature;

use App\Enums\LandingPage;
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

class SalesforceCustomersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.salesforce.auth_method' => 'client_credentials',
            'services.salesforce.client_id' => 'test-client-id',
            'services.salesforce.client_secret' => 'test-client-secret',
            'services.salesforce.url' => 'https://example.my.salesforce.com',
            'services.salesforce.retry_attempts' => 1,
        ]);
    }

    public function test_admins_and_managers_can_view_all_account_types_using_account_ids(): void
    {
        $this->fakeAccounts([
            ['Id' => '001000000000001AAA', 'Name' => 'Same customer name', 'Type' => 'Consultant', 'Phone' => '01234 567890', 'BillingCity' => 'London', 'Owner' => ['Name' => 'Alex Owner']],
            ['Id' => '001000000000002AAA', 'Name' => 'Same customer name', 'Type' => 'Contractor', 'BillingCity' => 'Birmingham'],
        ]);

        foreach ([User::factory()->admin()->create(), User::factory()->manager()->create()] as $actor) {
            $this->actingAs($actor);
            $component = Livewire::test(SalesforceCustomers::class)
                ->assertSee('Customer Name')->assertSee('Consultant')->assertSee('Contractor')
                ->assertSee('Owner')->assertSee('Alex Owner')
                ->assertDontSee('London')->assertDontSee('Birmingham')->assertDontSee('01234 567890');
            $records = $component->instance()->getTableRecords();
            $this->assertSame(['001000000000001AAA', '001000000000002AAA'], array_keys($records->items()));
            $this->assertSame('001000000000001AAA', $component->instance()->getTableRecordKey($records->first()));
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET' && ! str_contains($request->url(), '/services/oauth2/token'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "Type = 'Contractor'"));
    }

    public function test_users_without_salesforce_permission_cannot_open_either_section(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create());
        Livewire::test(SalesforceCustomers::class)->assertForbidden();
        Livewire::test(Salesforce::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_navigation_has_one_salesforce_entry_and_customers_before_projects(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $this->assertFalse(Salesforce::shouldRegisterNavigation());
        $this->assertTrue(SalesforceCustomers::shouldRegisterNavigation());
        $this->assertSame('Salesforce', SalesforceCustomers::getNavigationLabel());
        $this->assertSame(SalesforceCustomers::getUrl(), LandingPage::SalesforceProjects->url());
        $this->assertSame('Salesforce', LandingPage::SalesforceProjects->label());
        $links = Salesforce::subNavigationLinks(SalesforceCustomers::getUrl());
        $this->assertSame(['Customers', 'Projects', 'Visits'], array_column($links, 'label'));
        $this->assertTrue($links[0]['active']);
        $this->assertFalse($links[1]['active']);
        $links = Salesforce::subNavigationLinks(Salesforce::getUrl());
        $this->assertFalse($links[0]['active']);
        $this->assertTrue($links[1]['active']);
    }

    public function test_existing_salesforce_projects_table_remains_available(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getOpportunityStageOptions')->andReturn([]);
        $service->shouldReceive('getOpportunities')->andReturn(new LengthAwarePaginator([
            ['Id' => '006000000000001AAA', 'Name' => 'Existing Salesforce project', 'Project_Reference_Number__c' => '12345', 'StageName' => 'Open', 'Amount' => 100, 'CreatedDate' => '2026-01-01'],
        ], 1, 10, 1));
        Livewire::test(Salesforce::class)->assertSee('Existing Salesforce project')->assertSee('Project Name');
    }

    public function test_both_sections_render_the_shared_navigation_in_the_top_bar(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $this->fakeAccounts([]);

        foreach ([SalesforceCustomers::getUrl(), Salesforce::getUrl()] as $url) {
            $response = $this->get($url)->assertOk();
            $html = $response->getContent();
            $this->assertStringContainsString('hidden lg:flex mr-4', $html);
            $response->assertSee(SalesforceCustomers::getUrl(), false)->assertSee(Salesforce::getUrl(), false);
        }

        $links = SalesforceCustomers::subNavigationLinks(SalesforceCustomers::getUrl());
        $this->assertNotSame($links[0]['url'], $links[1]['url']);
        $this->assertFalse($links[1]['active']);
    }

    public function test_salesforce_access_does_not_grant_access_to_project_amounts(): void
    {
        $actor = User::factory()->technical()->create();
        $actor->permissionGroup->permissions()->syncWithoutDetaching([Permission::where('key', 'salesforce.view')->value('id')]);
        $this->actingAs($actor);
        $this->assertFalse($actor->can('pricing.view'));
        $service = $this->mock(SalesforceService::class);
        $service->shouldReceive('getOpportunityStageOptions')->andReturn([]);
        $service->shouldReceive('getOpportunities')
            ->withArgs(fn (int $page, int $perPage, ?string $search, ?string $sortColumn, ?string $sortDirection, array $fields): bool => ! in_array('Amount', $fields, true) && $sortColumn !== 'Amount')
            ->andReturn(new LengthAwarePaginator([], 0, 10, 1));

        Livewire::test(Salesforce::class)->assertTableColumnHidden('Amount')->sortTable('Amount', 'desc');
    }

    public function test_revoked_salesforce_permission_is_checked_on_table_requests(): void
    {
        $actor = User::factory()->manager()->create();
        $this->actingAs($actor);
        $this->fakeAccounts([]);
        $component = Livewire::test(SalesforceCustomers::class);
        $actor->permissionGroup->permissions()->detach(Permission::where('key', 'salesforce.view')->value('id'));
        $component->searchTable('Restricted')->assertForbidden();
    }

    public function test_account_search_escapes_input_and_allowlists_sorting(): void
    {
        $this->fakeAccounts([]);
        app(SalesforceService::class)->getAccounts(1, 25, "O'Reilly%_", 'Name DESC LIMIT 1', 'DESC;delete');
        Http::assertSent(function (Request $request): bool {
            $query = (string) ($request->data()['q'] ?? '');

            return str_contains($query, "Name LIKE '%O\\'Reilly\\%\\_%'")
                && str_contains($query, 'ORDER BY Name ASC, Id ASC')
                && ! str_contains($query, 'LIMIT 1')
                && ! str_contains($query, 'delete');
        });
    }

    public function test_customers_can_paginate_past_salesforces_two_thousand_row_offset_limit(): void
    {
        $first = array_map(fn (int $index): array => ['Id' => '001'.str_pad((string) $index, 12, '0', STR_PAD_LEFT), 'Name' => 'Account '.$index], range(1, 2000));
        $second = array_map(fn (int $index): array => ['Id' => '001'.str_pad((string) $index, 12, '0', STR_PAD_LEFT), 'Name' => 'Account '.$index], range(2001, 2020));
        $this->fakeAccounts($first, 2020, '/services/data/v65.0/query/cursor-2000', $second);
        $result = app(SalesforceService::class)->getAccounts(81, 25);
        $this->assertSame(2020, $result->total());
        $this->assertCount(20, $result->items());
        $this->assertSame('Account 2001', $result->first()['Name']);
        $this->assertSame('Account 2020', $result->last()['Name']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/query/cursor-2000'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), 'OFFSET'));
    }

    public function test_pagination_keeps_rows_when_a_page_spans_two_salesforce_batches(): void
    {
        $first = array_map(fn (int $index): array => ['Id' => (string) $index, 'Name' => 'Account '.$index], range(1, 30));
        $second = array_map(fn (int $index): array => ['Id' => (string) $index, 'Name' => 'Account '.$index], range(31, 50));
        $this->fakeAccounts($first, 50, '/services/data/v65.0/query/cursor-30', $second);
        $service = app(SalesforceService::class);
        $result = $service->getAccounts(2, 25);
        $this->assertCount(25, $result->items());
        $this->assertSame('Account 26', $result->first()['Name']);
        $this->assertSame('Account 50', $result->last()['Name']);
        $this->assertEmpty($service->getAccounts(3, 25)->items());
    }

    public function test_salesforce_failure_shows_a_friendly_notification(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        Http::fake(['*' => Http::response(['error' => 'denied'], 403)]);
        Livewire::test(SalesforceCustomers::class)->assertNotified('Customers could not be loaded');
    }

    public function test_salesforce_tables_are_unstriped_and_filter_at_source_with_accurate_totals(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com']);
            }
            if (str_contains($request->url(), '/sobjects/Account/describe')) {
                return Http::response(['fields' => [['name' => 'Type', 'picklistValues' => [
                    ['active' => true, 'value' => 'Contractor', 'label' => 'Contractor'],
                    ['active' => true, 'value' => 'Consultant', 'label' => 'Consultant'],
                ]]]]);
            }
            $query = (string) ($request->data()['q'] ?? '');
            if (str_contains($query, 'GROUP BY StageName')) {
                return Http::response(['records' => [['StageName' => 'Open'], ['StageName' => 'Details']]]);
            }
            $prefix = str_contains($query, 'FROM Account') ? '001' : '006';
            $filtered = str_contains($query, ' IN (');
            $records = [['Id' => $prefix.'000000000001AAA', 'Name' => 'First record', 'Type' => 'Contractor', 'StageName' => 'Open']];
            if (! $filtered) {
                $records[] = ['Id' => $prefix.'000000000002AAA', 'Name' => 'Second record', 'Type' => 'Consultant', 'StageName' => 'Details'];
            }

            return Http::response(['totalSize' => count($records), 'records' => $records, 'done' => true]);
        });

        foreach ([[SalesforceCustomers::class, 'type', 'Contractor'], [Salesforce::class, 'stage', 'Open']] as [$page, $filter, $value]) {
            $component = Livewire::test($page)->assertSee('First record')->assertSee('Second record');
            $this->assertFalse($component->instance()->getTable()->isStriped());
            $component->filterTable($filter, [$value])->assertSee('First record')->assertDontSee('Second record');
            $this->assertSame(1, $component->instance()->getTableRecords()->total());
            Livewire::test($page)->assertSee('First record')->assertDontSee('Second record');
            $component->filterTable($filter, [])->assertSee('Second record');
            $this->assertSame(2, $component->instance()->getTableRecords()->total());
        }

        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "Type IN ('Contractor')"));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "IsClosed = false AND IsWon = false AND StageName IN ('Open')"));
    }

    public function test_filter_values_are_escaped_and_combined_with_search_before_pagination(): void
    {
        $this->fakeAccounts([]);
        app(SalesforceService::class)->getAccounts(search: 'Test', types: ["O'Reilly", null, ['invalid']]);
        app(SalesforceService::class)->getOpportunities(search: 'Test', stages: ["Client's review"]);
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "Type IN ('O\\'Reilly') AND (Name LIKE '%Test%'"));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "StageName IN ('Client\\'s review') AND Name LIKE '%Test%'"));
    }

    public function test_owner_filter_uses_ids_and_applies_before_customer_pagination(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com']);
            }
            if (str_contains($request->url(), '/describe')) {
                return Http::response(['fields' => []]);
            }
            $query = (string) ($request->data()['q'] ?? '');
            if (str_contains($query, 'GROUP BY OwnerId')) {
                return Http::response(['records' => [
                    ['OwnerId' => '005000000000001AAA', 'Name' => 'Alex Owner'],
                    ['OwnerId' => '005000000000002AAA', 'Name' => 'Jamie Owner'],
                ]]);
            }
            $rows = [['Id' => '001000000000001AAA', 'Name' => 'Alex customer', 'Owner' => ['Name' => 'Alex Owner']]];
            if (! str_contains($query, "OwnerId IN ('005000000000001AAA')")) {
                $rows[] = ['Id' => '001000000000002AAA', 'Name' => 'Jamie customer', 'Owner' => ['Name' => 'Jamie Owner']];
            }

            return Http::response(['records' => $rows, 'totalSize' => count($rows)]);
        });
        $this->assertSame(['005000000000001AAA' => 'Alex Owner', '005000000000002AAA' => 'Jamie Owner'], app(SalesforceService::class)->getAccountOwnerOptions());
        $component = Livewire::test(SalesforceCustomers::class)->assertCanRenderTableColumn('Owner.Name')
            ->assertSee('Alex Owner')->assertSee('Jamie Owner')
            ->filterTable('owner', ['005000000000001AAA'])
            ->assertSee('Alex customer')->assertDontSee('Jamie customer');
        $this->assertSame(1, $component->instance()->getTableRecords()->total());
        Livewire::test(SalesforceCustomers::class)->assertSee('Alex customer')->assertDontSee('Jamie customer');
        $this->assertSame(['customer', 'type', 'owner'], array_keys($component->instance()->getTable()->getFilters()));
        app(SalesforceService::class)->getAccounts(sortColumn: 'Owner.Name', sortDirection: 'desc', owners: ['005000000000001AAA']);
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "OwnerId IN ('005000000000001AAA') ORDER BY Owner.Name DESC"));
    }

    public function test_owner_options_resolve_missing_grouped_names_from_users_by_id(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com']);
            }
            $query = (string) ($request->data()['q'] ?? '');
            if (str_contains($query, 'FROM User')) {
                return Http::response(['records' => [['Id' => '005000000000001AAA', 'Name' => 'Kris Price']]]);
            }

            return Http::response(['records' => [['OwnerId' => '005000000000001AAA'], ['OwnerId' => '005000000000002AAA']]]);
        });
        $this->assertSame(['005000000000001AAA' => 'Kris Price'], app(SalesforceService::class)->getAccountOwnerOptions());
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "FROM User WHERE Id IN ('005000000000001AAA', '005000000000002AAA')"));
    }

    public function test_past_project_counts_are_batched_and_cached_and_include_all_stages(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com']);
            }
            $query = (string) ($request->data()['q'] ?? '');
            if (str_contains($query, 'GROUP BY AccountId')) {
                return Http::response(['records' => [['AccountId' => '001000000000001AAA', 'projectCount' => 14]]]);
            }

            return Http::response(['totalSize' => 2, 'records' => [
                ['Id' => '001000000000001AAA', 'Name' => 'First customer'],
                ['Id' => '001000000000002AAA', 'Name' => 'Second customer'],
            ]]);
        });
        $service = app(SalesforceService::class);
        foreach ([1, 2] as $attempt) {
            $accounts = $service->getAccounts()->items();
            $this->assertSame(14, $accounts['001000000000001AAA']['PastProjectsCount']);
            $this->assertSame(0, $accounts['001000000000002AAA']['PastProjectsCount']);
        }
        $queries = Http::recorded()->map(fn (array $pair): string => (string) ($pair[0]->data()['q'] ?? ''))->filter(fn (string $query): bool => str_contains($query, 'GROUP BY AccountId'));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString("AccountId IN ('001000000000001AAA', '001000000000002AAA')", $queries->first());
        $this->assertStringNotContainsString('IsClosed', $queries->first());
        $this->assertStringNotContainsString('IsWon', $queries->first());
    }

    public function test_customer_name_filter_searches_names_but_filters_by_account_id(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com']);
            }
            if (str_contains($request->url(), '/describe')) {
                return Http::response(['fields' => []]);
            }
            $query = (string) ($request->data()['q'] ?? '');
            if (str_contains($query, 'GROUP BY')) {
                return Http::response(['records' => []]);
            }
            $records = [
                ['Id' => '001000000000001AAA', 'Name' => 'Same customer', 'Type' => 'Contractor'],
                ['Id' => '001000000000002AAA', 'Name' => 'Same customer', 'Type' => 'Consultant'],
            ];
            if (str_contains($query, "Id IN ('001000000000002AAA')")) {
                $records = [$records[1]];
            }

            return Http::response(['totalSize' => count($records), 'records' => $records]);
        });
        $this->assertSame(['001000000000001AAA' => 'Same customer', '001000000000002AAA' => 'Same customer'], app(SalesforceService::class)->getAccountNameOptions('Same'));
        $component = Livewire::test(SalesforceCustomers::class)->filterTable('customer', ['001000000000002AAA']);
        $this->assertSame(['001000000000002AAA'], array_keys($component->instance()->getTableRecords()->items()));
        $this->assertSame(1, $component->instance()->getTableRecords()->total());
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "FROM Account WHERE Id IN ('001000000000002AAA')"));
    }

    /** @param array<int, array<string, mixed>> $records
     * @param  array<int, array<string, mixed>>  $nextRecords
     */
    private function fakeAccounts(array $records, ?int $total = null, ?string $nextUrl = null, array $nextRecords = []): void
    {
        Http::fake(function (Request $request) use ($records, $total, $nextUrl, $nextRecords) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['access_token' => 'test-token', 'instance_url' => 'https://example.my.salesforce.com', 'expires_in' => 3600]);
            }

            if ($nextUrl !== null && str_ends_with($request->url(), $nextUrl)) {
                return Http::response(['totalSize' => $total, 'done' => true, 'records' => $nextRecords]);
            }

            return Http::response(['totalSize' => $total ?? count($records), 'done' => $nextUrl === null, 'nextRecordsUrl' => $nextUrl, 'records' => $records]);
        });
    }
}
