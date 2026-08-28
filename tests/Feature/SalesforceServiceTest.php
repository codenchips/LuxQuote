<?php

namespace Tests\Feature;

use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_search_accounts_returns_minimal_account_results(): void
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
                            'Id' => '001000000000001AAA',
                            'Name' => 'Example Contractor',
                            'BillingStreet' => '1 Test Street',
                            'BillingCity' => 'Birmingham',
                            'BillingState' => 'West Midlands',
                            'BillingPostalCode' => 'B1 1AA',
                            'Phone' => '0121 000 0000',
                            'CEF_Region__c' => 'Midlands',
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $accounts = app(SalesforceService::class)->searchAccounts('Contractor');

        $this->assertSame([
            [
                'id' => '001000000000001AAA',
                'name' => 'Example Contractor',
                'billing_street' => '1 Test Street',
                'billing_city' => 'Birmingham',
                'billing_state' => 'West Midlands',
                'billing_postal_code' => 'B1 1AA',
                'phone' => '0121 000 0000',
                'cef_region' => 'Midlands',
            ],
        ], $accounts);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/services/data/v65.0/query/')
            && $request->hasHeader('Authorization', 'Bearer live-test-token')
            && str_contains((string) ($request->data()['q'] ?? ''), 'SELECT Id, Name, BillingStreet, BillingCity, BillingState, BillingPostalCode, Phone, CEF_Region__c FROM Account')
            && str_contains((string) ($request->data()['q'] ?? ''), "Type = 'Contractor'")
            && str_contains((string) ($request->data()['q'] ?? ''), "Name LIKE '%Contractor%'")
            && str_contains((string) ($request->data()['q'] ?? ''), 'OFFSET 0'));
    }

    public function test_search_accounts_falls_back_when_optional_cef_region_field_is_unavailable(): void
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
                $query = (string) ($request->data()['q'] ?? '');

                if (str_contains($query, 'CEF_Region__c')) {
                    return Http::response([[
                        'message' => 'No such column CEF_Region__c on entity Account.',
                        'errorCode' => 'INVALID_FIELD',
                    ]], 400);
                }

                return Http::response([
                    'records' => [
                        [
                            'Id' => '001000000000001AAA',
                            'Name' => 'Example Contractor',
                            'BillingStreet' => '1 Test Street',
                            'BillingCity' => 'Birmingham',
                            'BillingState' => 'West Midlands',
                            'BillingPostalCode' => 'B1 1AA',
                            'Phone' => '0121 000 0000',
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $accounts = app(SalesforceService::class)->searchAccounts('Contractor');

        $this->assertSame('Example Contractor', $accounts[0]['name']);
        $this->assertNull($accounts[0]['cef_region']);
    }

    public function test_search_accounts_result_reports_salesforce_failures_without_throwing(): void
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
                    'message' => 'sObject type Account is not supported.',
                    'errorCode' => 'INVALID_TYPE',
                ]], 400);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->searchAccountsResult('Contractor');

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['records']);
        $this->assertStringContainsString('Salesforce Account search failed', $result['message']);
    }

    public function test_fetch_public_calendars_excludes_user_calendars(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            return Http::response([
                'records' => [[
                    'Id' => '023000000000001AAA',
                    'Name' => 'Lighting Design',
                    'Type' => 'Public',
                ]],
            ]);
        });

        $result = app(SalesforceService::class)->fetchPublicCalendars(25);

        $this->assertTrue($result['success']);
        $this->assertSame('Lighting Design', $result['records'][0]['Name']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/services/data/v65.0/query/')
            && ($request->data()['q'] ?? null) === "SELECT Id, Name, Type FROM Calendar WHERE Type = 'Public' ORDER BY Name ASC LIMIT 25");
    }

    public function test_fetch_calendar_bookings_queries_events_owned_by_the_calendar(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            return Http::response([
                'records' => [[
                    'Id' => '00U000000000001AAA',
                    'Subject' => 'Hospital survey',
                ]],
            ]);
        });

        $result = app(SalesforceService::class)->fetchCalendarBookings(
            '023000000000001AAA',
            '2026-08-31T23:00:00Z',
            '2026-09-30T22:59:59Z',
            25,
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Hospital survey', $result['records'][0]['Subject']);

        Http::assertSent(function (Request $request): bool {
            $query = (string) ($request->data()['q'] ?? '');

            return $request->method() === 'GET'
                && str_contains($request->url(), '/services/data/v65.0/query/')
                && str_contains($query, "OwnerId = '023000000000001AAA'")
                && str_contains($query, 'Owner.Name')
                && str_contains($query, 'CreatedBy.Name')
                && str_contains($query, 'Subject, Type, StartDateTime')
                && str_contains($query, 'EndDateTime >= 2026-08-31T23:00:00Z')
                && str_contains($query, 'StartDateTime < 2026-09-30T22:59:59Z')
                && str_contains($query, 'ORDER BY StartDateTime ASC LIMIT 25');
        });
    }

    public function test_fetch_event_type_options_returns_active_salesforce_picklist_values(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            if (str_contains($request->url(), '/sobjects/Event/describe')) {
                return Http::response([
                    'fields' => [[
                        'name' => 'Type',
                        'updateable' => true,
                        'createable' => true,
                        'picklistValues' => [
                            ['active' => true, 'label' => 'Meeting', 'value' => 'Meeting'],
                            ['active' => true, 'label' => 'Other', 'value' => 'Other'],
                            ['active' => false, 'label' => 'Legacy', 'value' => 'Legacy'],
                        ],
                    ]],
                ]);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->fetchEventTypeOptions();

        $this->assertTrue($result['success']);
        $this->assertSame([
            'Meeting' => 'Meeting',
            'Other' => 'Other',
        ], $result['options']);
        $this->assertTrue($result['updateable']['Type']);
        $this->assertTrue($result['createable']['Type']);
    }

    public function test_fetch_calendar_bookings_falls_back_when_optional_fields_are_not_readable(): void
    {
        $calendarQueries = [];

        Http::fake(function (Request $request) use (&$calendarQueries) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            $query = (string) ($request->data()['q'] ?? '');
            $calendarQueries[] = $query;

            if (str_contains($query, 'Owner.Name') || str_contains($query, 'Subject, Type,')) {
                return Http::response([[
                    'message' => 'No such column',
                    'errorCode' => 'INVALID_FIELD',
                ]], 400);
            }

            return Http::response([
                'records' => [[
                    'Id' => '00U000000000001AAA',
                    'Subject' => 'Readable visit',
                    'StartDateTime' => '2026-09-04T09:00:00.000+0000',
                    'EndDateTime' => '2026-09-04T10:00:00.000+0000',
                    'IsAllDayEvent' => false,
                    'Location' => 'Boardroom',
                    'OwnerId' => '023000000000001AAA',
                ]],
            ]);
        });

        $result = app(SalesforceService::class)->fetchCalendarBookings(
            '023000000000001AAA',
            '2026-09-01T00:00:00Z',
            '2026-10-01T00:00:00Z',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Readable visit', $result['records'][0]['Subject']);
        $this->assertCount(3, $calendarQueries);
    }

    public function test_update_calendar_booking_reports_non_updateable_fields_without_patching(): void
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
                return Http::response(['records' => [['Id' => '00U000000000001AAA']]]);
            }

            if (str_contains($request->url(), '/sobjects/Event/describe')) {
                return Http::response([
                    'fields' => [[
                        'name' => 'Location',
                        'updateable' => false,
                    ]],
                ]);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->updateCalendarBooking(
            '023000000000001AAA',
            '00U000000000001AAA',
            ['Location' => 'Showroom'],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Salesforce does not permit updates to: Location.', $result['message']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_create_calendar_booking_verifies_public_calendar_and_creates_event(): void
    {
        $createableFields = [
            'Subject',
            'Location',
            'Type',
            'StartDateTime',
            'EndDateTime',
            'IsAllDayEvent',
            'OwnerId',
        ];

        Http::fake(function (Request $request) use ($createableFields) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/query/')) {
                return Http::response(['records' => [['Id' => '023000000000001AAA']]]);
            }

            if (str_contains($request->url(), '/sobjects/Event/describe')) {
                return Http::response([
                    'fields' => array_map(
                        fn (string $field): array => ['name' => $field, 'createable' => true],
                        $createableFields,
                    ),
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/sobjects/Event')) {
                return Http::response([
                    'id' => '00U000000000NEWAAA',
                    'success' => true,
                    'errors' => [],
                ], 201);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->createCalendarBooking(
            '023000000000001AAA',
            [
                'Subject' => 'Customer visit',
                'Location' => 'Boardroom',
                'Type' => 'Meeting',
                'StartDateTime' => '2026-09-04T08:15:00Z',
                'EndDateTime' => '2026-09-04T10:00:00Z',
                'IsAllDayEvent' => false,
                'CreatedById' => '005000000000001AAA',
            ],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('00U000000000NEWAAA', $result['eventId']);

        Http::assertSent(function (Request $request): bool {
            $query = (string) ($request->data()['q'] ?? '');

            return $request->method() === 'GET'
                && str_contains($request->url(), '/services/data/v65.0/query/')
                && str_contains($query, "Id = '023000000000001AAA'")
                && str_contains($query, "Type = 'Public'");
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/sobjects/Event')
            && $request->data() === [
                'Subject' => 'Customer visit',
                'Location' => 'Boardroom',
                'Type' => 'Meeting',
                'StartDateTime' => '2026-09-04T08:15:00Z',
                'EndDateTime' => '2026-09-04T10:00:00Z',
                'IsAllDayEvent' => false,
                'OwnerId' => '023000000000001AAA',
            ]);
    }

    public function test_create_calendar_booking_rejects_inaccessible_public_calendar_without_posting(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            return Http::response(['records' => []]);
        });

        $result = app(SalesforceService::class)->createCalendarBooking(
            '023000000000001AAA',
            [
                'Subject' => 'Should not be created',
                'StartDateTime' => '2026-09-04T08:15:00Z',
                'EndDateTime' => '2026-09-04T10:00:00Z',
                'IsAllDayEvent' => false,
            ],
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('has not been created', $result['message']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/sobjects/Event'));
    }

    public function test_create_calendar_booking_reports_non_createable_fields_without_posting(): void
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
                return Http::response(['records' => [['Id' => '023000000000001AAA']]]);
            }

            if (str_contains($request->url(), '/sobjects/Event/describe')) {
                return Http::response([
                    'fields' => [
                        ['name' => 'Subject', 'createable' => true],
                        ['name' => 'StartDateTime', 'createable' => true],
                        ['name' => 'EndDateTime', 'createable' => true],
                        ['name' => 'IsAllDayEvent', 'createable' => true],
                        ['name' => 'OwnerId', 'createable' => false],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->createCalendarBooking(
            '023000000000001AAA',
            [
                'Subject' => 'Customer visit',
                'StartDateTime' => '2026-09-04T08:15:00Z',
                'EndDateTime' => '2026-09-04T10:00:00Z',
                'IsAllDayEvent' => false,
            ],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Salesforce does not permit event creation with: Calendar owner.', $result['message']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/sobjects/Event'));
    }

    public function test_create_calendar_booking_returns_friendly_message_for_salesforce_permission_failure(): void
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
                return Http::response(['records' => [['Id' => '023000000000001AAA']]]);
            }

            if (str_contains($request->url(), '/sobjects/Event/describe')) {
                return Http::response([], 403);
            }

            return Http::response([[
                'message' => 'insufficient access rights on object id',
                'errorCode' => 'INSUFFICIENT_ACCESS_OR_READONLY',
            ]], 403);
        });

        $result = app(SalesforceService::class)->createCalendarBooking(
            '023000000000001AAA',
            [
                'Subject' => 'Customer visit',
                'StartDateTime' => '2026-09-04T08:15:00Z',
                'EndDateTime' => '2026-09-04T10:00:00Z',
                'IsAllDayEvent' => false,
            ],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Salesforce does not permit the integration user to create events on this calendar.',
            $result['message'],
        );
    }

    public function test_update_calendar_booking_returns_friendly_message_for_salesforce_permission_failure(): void
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
                return Http::response(['records' => [['Id' => '00U000000000001AAA']]]);
            }

            if (str_contains($request->url(), '/sobjects/Event/describe')) {
                return Http::response([], 403);
            }

            if ($request->method() === 'PATCH') {
                return Http::response([[
                    'message' => 'insufficient access rights on object id',
                    'errorCode' => 'INSUFFICIENT_ACCESS_OR_READONLY',
                ]], 403);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->updateCalendarBooking(
            '023000000000001AAA',
            '00U000000000001AAA',
            ['Subject' => 'Updated visit'],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Salesforce does not permit the integration user to update this event.', $result['message']);
    }

    public function test_update_calendar_booking_verifies_calendar_ownership_and_allows_only_editable_fields(): void
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
                return Http::response(['records' => [['Id' => '00U000000000001AAA']]]);
            }

            if ($request->method() === 'PATCH' && str_contains($request->url(), '/sobjects/Event/00U000000000001AAA')) {
                return Http::response(null, 204);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->updateCalendarBooking(
            '023000000000001AAA',
            '00U000000000001AAA',
            [
                'Subject' => 'Updated visit',
                'Location' => 'Boardroom',
                'Type' => 'Meeting',
                'StartDateTime' => '2026-09-04T09:00:00Z',
                'EndDateTime' => '2026-09-04T10:00:00Z',
                'IsAllDayEvent' => false,
                'OwnerId' => '005000000000001AAA',
                'CreatedById' => '005000000000002AAA',
            ],
        );

        $this->assertTrue($result['success']);

        Http::assertSent(function (Request $request): bool {
            $query = (string) ($request->data()['q'] ?? '');

            return $request->method() === 'GET'
                && str_contains($request->url(), '/services/data/v65.0/query/')
                && str_contains($query, "Id = '00U000000000001AAA'")
                && str_contains($query, "OwnerId = '023000000000001AAA'");
        });
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            return $request->data() === [
                'Subject' => 'Updated visit',
                'Location' => 'Boardroom',
                'Type' => 'Meeting',
                'StartDateTime' => '2026-09-04T09:00:00Z',
                'EndDateTime' => '2026-09-04T10:00:00Z',
                'IsAllDayEvent' => false,
            ];
        });
    }

    public function test_update_calendar_booking_rejects_event_from_another_calendar(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            return Http::response(['records' => []]);
        });

        $result = app(SalesforceService::class)->updateCalendarBooking(
            '023000000000001AAA',
            '00U000000000001AAA',
            ['Subject' => 'Should not update'],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('The event was not found on the configured Salesforce calendar.', $result['message']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_delete_calendar_booking_verifies_calendar_ownership_and_deletes_event(): void
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
                return Http::response(['records' => [['Id' => '00U000000000001AAA']]]);
            }

            if ($request->method() === 'DELETE' && str_contains($request->url(), '/sobjects/Event/00U000000000001AAA')) {
                return Http::response(null, 204);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->deleteCalendarBooking(
            '023000000000001AAA',
            '00U000000000001AAA',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('00U000000000001AAA', $result['eventId']);

        Http::assertSent(function (Request $request): bool {
            $query = (string) ($request->data()['q'] ?? '');

            return $request->method() === 'GET'
                && str_contains($request->url(), '/services/data/v65.0/query/')
                && str_contains($query, "Id = '00U000000000001AAA'")
                && str_contains($query, "OwnerId = '023000000000001AAA'");
        });
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/services/data/v65.0/sobjects/Event/00U000000000001AAA'));
    }

    public function test_delete_calendar_booking_rejects_event_from_another_calendar(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'live-test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                    'expires_in' => 3600,
                ]);
            }

            return Http::response(['records' => []]);
        });

        $result = app(SalesforceService::class)->deleteCalendarBooking(
            '023000000000001AAA',
            '00U000000000001AAA',
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('has not been removed', $result['message']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_delete_calendar_booking_returns_friendly_message_for_salesforce_permission_failure(): void
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
                return Http::response(['records' => [['Id' => '00U000000000001AAA']]]);
            }

            return Http::response([[
                'message' => 'insufficient access rights on object id',
                'errorCode' => 'INSUFFICIENT_ACCESS_OR_READONLY',
            ]], 403);
        });

        $result = app(SalesforceService::class)->deleteCalendarBooking(
            '023000000000001AAA',
            '00U000000000001AAA',
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Salesforce does not permit the integration user to remove this event. The event has not been removed.',
            $result['message'],
        );
    }

    public function test_delete_calendar_booking_handles_connection_failure_without_throwing(): void
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
                return Http::response(['records' => [['Id' => '00U000000000001AAA']]]);
            }

            throw new ConnectionException('Salesforce timed out.');
        });

        $result = app(SalesforceService::class)->deleteCalendarBooking(
            '023000000000001AAA',
            '00U000000000001AAA',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Salesforce could not be reached. The event has not been removed.', $result['message']);
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
                            'FirstName' => 'Jamie',
                            'LastName' => 'Engineer',
                            'Email' => 'jamie.engineer@example.com.invalid',
                            'Title' => 'Senior Project Engineer',
                            'MobilePhone' => '07961 805168',
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
            'first_name' => 'Jamie',
            'last_name' => 'Engineer',
            'email' => 'jamie.engineer@example.com',
            'title' => 'Senior Project Engineer',
            'mobile_phone' => '07961 805168',
        ], $owner);

        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), 'SELECT Id, OwnerId FROM Opportunity'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), "SELECT Id, Name, FirstName, LastName, Email, Title, MobilePhone FROM User WHERE Id = '005000000000001AAA'"));
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
