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
            'services.salesforce.auth_method' => 'client_credentials',
            'services.salesforce.client_id' => 'test-client-id',
            'services.salesforce.client_secret' => 'test-client-secret',
            'services.salesforce.url' => 'https://example.my.salesforce.com',
            'services.salesforce.jwt_audience' => null,
            'services.salesforce.jwt_private_key' => null,
            'services.salesforce.jwt_private_key_path' => null,
            'services.salesforce.jwt_subject' => null,
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

    public function test_jwt_bearer_authenticates_and_returns_options(): void
    {
        $this->travelTo('2026-07-02 10:00:00');

        $privateKey = $this->privateKey();

        config([
            'services.salesforce.auth_method' => 'jwt_bearer',
            'services.salesforce.jwt_audience' => 'https://test.salesforce.com',
            'services.salesforce.jwt_private_key' => $privateKey,
            'services.salesforce.jwt_subject' => 'integration.user@example.com.sandbox',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'jwt-test-token',
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

        Http::assertSent(function (Request $request) use ($privateKey): bool {
            if (
                $request->method() !== 'POST'
                || $request->url() !== 'https://test.salesforce.com/services/oauth2/token'
                || $request->data()['grant_type'] !== 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            ) {
                return false;
            }

            $assertion = (string) $request->data()['assertion'];
            $payload = $this->jwtPayload($assertion);

            return $payload['iss'] === 'test-client-id'
                && $payload['sub'] === 'integration.user@example.com.sandbox'
                && $payload['aud'] === 'https://test.salesforce.com'
                && $payload['exp'] === now()->addMinutes(5)->timestamp
                && $this->jwtSignatureIsValid($assertion, $privateKey);
        });

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/services/data/v65.0/query/')
            && $request->hasHeader('Authorization', 'Bearer jwt-test-token'));
    }

    public function test_jwt_bearer_authentication_failure_returns_empty_options_without_querying_salesforce(): void
    {
        config([
            'services.salesforce.auth_method' => 'jwt_bearer',
            'services.salesforce.jwt_audience' => 'https://test.salesforce.com',
            'services.salesforce.jwt_private_key' => null,
            'services.salesforce.jwt_subject' => 'integration.user@example.com.sandbox',
        ]);

        Http::fake();

        $options = app(SalesforceService::class)->searchOpportunities('Hartest');

        $this->assertSame([], $options);
        Http::assertNothingSent();
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

    public function test_opportunity_detail_fetch_continues_when_relationship_fields_fail(): void
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
                $soql = (string) ($request->data()['q'] ?? '');

                if (str_contains($soql, 'Owner.Email')) {
                    return Http::response([[
                        'message' => 'No such column Owner.Email on entity Opportunity.',
                        'errorCode' => 'INVALID_FIELD',
                    ]], 400);
                }

                return Http::response([
                    'records' => [
                        [
                            'Id' => '006000000000001AAA',
                            'Name' => 'Hartest Primary School',
                            'Project_Reference_Number__c' => '22600',
                            'Miscellaneous_Customer_Name__c' => 'Hartest Customer',
                            'CEF_Branch__c' => '001000000000001AAA',
                            'CEF_Branch__r' => ['Name' => 'Birmingham Central'],
                            'CEF_Cover__c' => 'CEF North',
                            'Amount' => 1000,
                            'OwnerId' => '005000000000001AAA',
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $opportunity = app(SalesforceService::class)->getOpportunityById('006000000000001AAA');

        $this->assertSame([
            'Id' => '006000000000001AAA',
            'Name' => 'Hartest Primary School',
            'Project_Reference_Number__c' => '22600',
            'Miscellaneous_Customer_Name__c' => 'Hartest Customer',
            'CEF_Branch__c' => '001000000000001AAA',
            'CEF_Branch__r' => ['Name' => 'Birmingham Central'],
            'CEF_Cover__c' => 'CEF North',
            'Amount' => 1000,
            'OwnerId' => '005000000000001AAA',
        ], $opportunity);
        $this->assertSame(1, $this->recordedRequestCount('/services/oauth2/token'));
        $this->assertSame(3, $this->recordedRequestCount('/services/data/v65.0/query/'));
    }

    public function test_opportunity_detail_fetch_continues_when_branch_field_is_unavailable(): void
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
                $soql = (string) ($request->data()['q'] ?? '');

                if (str_contains($soql, 'CEF_Branch__c')) {
                    return Http::response([[
                        'message' => 'No such column CEF_Branch__c on entity Opportunity.',
                        'errorCode' => 'INVALID_FIELD',
                    ]], 400);
                }

                if (str_contains($soql, 'Owner.Email')) {
                    return Http::response([
                        'records' => [[
                            'Id' => '006000000000001AAA',
                            'Owner' => ['Name' => 'Jamie Engineer', 'Email' => 'jamie@example.com'],
                            'Account' => ['Name' => 'Example Customer'],
                        ]],
                    ]);
                }

                return Http::response([
                    'records' => [[
                        'Id' => '006000000000001AAA',
                        'Name' => 'Hartest Primary School',
                        'Project_Reference_Number__c' => '22600',
                        'Miscellaneous_Customer_Name__c' => 'Hartest Customer',
                        'CEF_Cover__c' => 'CEF North',
                        'Amount' => 1000,
                        'OwnerId' => '005000000000001AAA',
                    ]],
                ]);
            }

            return Http::response([], 500);
        });

        $opportunity = app(SalesforceService::class)->getOpportunityById('006000000000001AAA');

        $this->assertSame('Hartest Primary School', $opportunity['Name']);
        $this->assertSame('Jamie Engineer', $opportunity['Owner']['Name']);
        $this->assertArrayNotHasKey('CEF_Branch__c', $opportunity);
    }

    public function test_account_linked_to_opportunity_can_be_fetched_with_all_available_fields(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            if (str_contains($request->url(), '/sobjects/Account/describe')) {
                return Http::response([
                    'fields' => [
                        ['name' => 'Id'],
                        ['name' => 'Name'],
                        ['name' => 'BillingCity'],
                    ],
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/query/')) {
                $soql = (string) ($request->data()['q'] ?? '');

                if (str_contains($soql, 'FROM Opportunity')) {
                    return Http::response([
                        'records' => [
                            [
                                'Id' => '006000000000001AAA',
                                'AccountId' => '001000000000001AAA',
                                'End_Client_ID__c' => null,
                            ],
                        ],
                    ]);
                }

                if (str_contains($soql, 'FROM Account')) {
                    return Http::response([
                        'records' => [
                            [
                                'Id' => '001000000000001AAA',
                                'Name' => 'Hartest Customer',
                                'BillingCity' => 'Bury St Edmunds',
                            ],
                        ],
                    ]);
                }
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->fetchAccountForOpportunity('006000000000001AAA');

        $this->assertTrue($result['success']);
        $this->assertSame('006000000000001AAA', $result['opportunityId']);
        $this->assertSame('001000000000001AAA', $result['accountId']);
        $this->assertSame([
            'Id' => '001000000000001AAA',
            'Name' => 'Hartest Customer',
            'BillingCity' => 'Bury St Edmunds',
        ], $result['record']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/services/data/v65.0/query/')
            && str_contains((string) ($request->data()['q'] ?? ''), 'SELECT Id, AccountId, End_Client_ID__c FROM Opportunity'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sobjects/Account/describe'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/services/data/v65.0/query/')
            && str_contains((string) ($request->data()['q'] ?? ''), "SELECT Id, Name, BillingCity FROM Account WHERE Id = '001000000000001AAA' LIMIT 1"));
    }

    public function test_users_linked_to_opportunity_can_be_fetched_with_all_available_fields(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            if (str_contains($request->url(), '/sobjects/User/describe')) {
                return Http::response([
                    'fields' => [
                        ['name' => 'Id'],
                        ['name' => 'Name'],
                        ['name' => 'Email'],
                    ],
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/query/')) {
                $soql = (string) ($request->data()['q'] ?? '');

                if (str_contains($soql, 'FROM Opportunity')) {
                    return Http::response([
                        'records' => [[
                            'Id' => '006000000000001AAA',
                            'OwnerId' => '005000000000001AAA',
                            'CreatedById' => '005000000000002AAA',
                        ]],
                    ]);
                }

                if (str_contains($soql, 'FROM User')) {
                    return Http::response([
                        'records' => [
                            [
                                'Id' => '005000000000001AAA',
                                'Name' => 'Opportunity Owner',
                                'Email' => 'owner@example.com',
                            ],
                            [
                                'Id' => '005000000000002AAA',
                                'Name' => 'Opportunity Creator',
                                'Email' => 'creator@example.com',
                            ],
                        ],
                    ]);
                }
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->fetchUsersForOpportunity('006000000000001AAA');

        $this->assertTrue($result['success']);
        $this->assertSame([
            [
                'Id' => '005000000000001AAA',
                'Name' => 'Opportunity Owner',
                'Email' => 'owner@example.com',
                '_OpportunityRelationships' => ['Owner'],
            ],
            [
                'Id' => '005000000000002AAA',
                'Name' => 'Opportunity Creator',
                'Email' => 'creator@example.com',
                '_OpportunityRelationships' => ['CreatedBy'],
            ],
        ], $result['records']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/services/data/v65.0/query/')
            && str_contains((string) ($request->data()['q'] ?? ''), 'SELECT Id, OwnerId, CreatedById FROM Opportunity'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sobjects/User/describe'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/services/data/v65.0/query/')
            && str_contains((string) ($request->data()['q'] ?? ''), 'SELECT Id, Name, Email FROM User WHERE Id IN'));
    }

    public function test_opportunity_owner_name_and_email_are_fetched_from_the_linked_user(): void
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
                $soql = (string) ($request->data()['q'] ?? '');

                if (str_contains($soql, 'FROM Opportunity')) {
                    return Http::response([
                        'records' => [[
                            'Id' => '006000000000001AAA',
                            'OwnerId' => '005000000000001AAA',
                        ]],
                    ]);
                }

                if (str_contains($soql, 'FROM User')) {
                    return Http::response([
                        'records' => [[
                            'Id' => '005000000000001AAA',
                            'Name' => 'Jamie Engineer',
                            'Email' => 'jamie.engineer@example.com.invalid',
                        ]],
                    ]);
                }
            }

            return Http::response([], 500);
        });

        $owner = app(SalesforceService::class)->getOpportunityOwner('006000000000001AAA');

        $this->assertSame([
            'id' => '005000000000001AAA',
            'name' => 'Jamie Engineer',
            'email' => 'jamie.engineer@example.com',
        ], $owner);

        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), 'SELECT Id, OwnerId FROM Opportunity'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "SELECT Id, Name, Email FROM User WHERE Id = '005000000000001AAA'"));
    }

    public function test_opportunity_owner_lookup_returns_null_when_user_fields_are_not_permitted(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            if (str_contains((string) ($request->data()['q'] ?? ''), 'FROM Opportunity')) {
                return Http::response([
                    'records' => [[
                        'Id' => '006000000000001AAA',
                        'OwnerId' => '005000000000001AAA',
                    ]],
                ]);
            }

            if (str_contains((string) ($request->data()['q'] ?? ''), 'FROM User')) {
                return Http::response([[
                    'message' => 'Insufficient permissions: secure query included inaccessible field',
                    'errorCode' => 'INSUFFICIENT_ACCESS',
                ]], 403);
            }

            return Http::response([], 500);
        });

        $owner = app(SalesforceService::class)->getOpportunityOwner('006000000000001AAA');

        $this->assertNull($owner);
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

    public function test_jwt_bearer_authentication_token_is_cached_between_requests(): void
    {
        config([
            'services.salesforce.auth_method' => 'jwt_bearer',
            'services.salesforce.jwt_audience' => 'https://test.salesforce.com',
            'services.salesforce.jwt_private_key' => $this->privateKey(),
            'services.salesforce.jwt_subject' => 'integration.user@example.com.sandbox',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'cached-jwt-test-token',
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

    private function privateKey(): string
    {
        $key = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key);

        $exported = openssl_pkey_export($key, $privateKey);

        $this->assertTrue($exported);

        return $privateKey;
    }

    /**
     * @return array<string, mixed>
     */
    private function jwtPayload(string $jwt): array
    {
        $segments = explode('.', $jwt);

        $this->assertCount(3, $segments);

        $payload = json_decode($this->base64UrlDecode($segments[1]), true);

        $this->assertIsArray($payload);

        return $payload;
    }

    private function jwtSignatureIsValid(string $jwt, string $privateKey): bool
    {
        $segments = explode('.', $jwt);

        $this->assertCount(3, $segments);

        $details = openssl_pkey_get_details(openssl_pkey_get_private($privateKey));

        $this->assertIsArray($details);

        return openssl_verify(
            $segments[0].'.'.$segments[1],
            $this->base64UrlDecode($segments[2]),
            $details['key'],
            OPENSSL_ALGO_SHA256,
        ) === 1;
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
