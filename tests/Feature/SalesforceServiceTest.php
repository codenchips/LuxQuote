<?php

namespace Tests\Feature;

use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesforceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.salesforce.client_id' => 'test-client-id',
            'services.salesforce.client_secret' => 'test-client-secret',
            'services.salesforce.url' => 'https://example.my.salesforce.com',
        ]);
    }

    public function test_search_opportunities_authenticates_and_returns_options(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/query/')) {
                return Http::response([
                    'records' => [
                        [
                            'Id' => '006000000000001AAA',
                            'Name' => 'Hartest Primary School',
                            'Project_Reference_Number__c' => '22600',
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $options = app(SalesforceService::class)->searchOpportunities('Hartest');

        $this->assertSame([
            '006000000000001AAA' => 'Hartest Primary School (22600)',
        ], $options);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/services/oauth2/token')
            && $request->data()['grant_type'] === 'client_credentials'
            && $request->data()['client_id'] === 'test-client-id'
            && $request->data()['client_secret'] === 'test-client-secret');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/services/data/v65.0/query/')
            && $request->hasHeader('Authorization', 'Bearer live-test-token')
            && str_contains((string) ($request->data()['q'] ?? ''), "Name LIKE '%Hartest%'")
            && str_contains((string) ($request->data()['q'] ?? ''), 'IsClosed = false')
            && str_contains((string) ($request->data()['q'] ?? ''), 'IsWon = false'));
    }

    public function test_authentication_failure_returns_empty_options_without_querying_salesforce(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response(['error' => 'invalid_client'], 401);
            }

            return Http::response([], 500);
        });

        $options = app(SalesforceService::class)->searchOpportunities('Hartest');

        $this->assertSame([], $options);
        $this->assertSame(1, $this->recordedRequestCount('/services/oauth2/token'));
        $this->assertSame(0, $this->recordedRequestCount('/services/data/v65.0/query/'));
    }

    public function test_query_failure_returns_empty_options_after_successful_authentication(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/query/')) {
                return Http::response([[
                    'message' => 'SOQL query failed',
                    'errorCode' => 'MALFORMED_QUERY',
                ]], 400);
            }

            return Http::response([], 500);
        });

        $options = app(SalesforceService::class)->searchOpportunities('Hartest');

        $this->assertSame([], $options);
        $this->assertSame(1, $this->recordedRequestCount('/services/oauth2/token'));
        $this->assertSame(1, $this->recordedRequestCount('/services/data/v65.0/query/'));
    }

    public function test_authentication_token_is_cached_between_requests(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'cached-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/query/')) {
                return Http::response([
                    'records' => [
                        [
                            'Id' => '006000000000001AAA',
                            'Name' => 'Hartest Primary School',
                            'Project_Reference_Number__c' => '22600',
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $salesforce = app(SalesforceService::class);

        $salesforce->searchOpportunities('Hartest');
        $salesforce->searchOpportunities('Hartest');

        $this->assertSame(1, $this->recordedRequestCount('/services/oauth2/token'));
        $this->assertSame(2, $this->recordedRequestCount('/services/data/v65.0/query/'));
    }

    private function recordedRequestCount(string $urlContains): int
    {
        return Http::recorded()
            ->filter(fn (array $record): bool => str_contains($record[0]->url(), $urlContains))
            ->count();
    }
}
