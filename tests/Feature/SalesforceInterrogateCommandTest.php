<?php

namespace Tests\Feature;

use App\Services\SalesforceService;
use Tests\TestCase;

class SalesforceInterrogateCommandTest extends TestCase
{
    public function test_interrogate_command_can_output_json_records(): void
    {
        $this->fakeSalesforceService();

        $this->artisan('salesforce:interrogate --format=json --limit=2')
            ->expectsOutput(json_encode($this->records(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->assertSuccessful();
    }

    public function test_interrogate_command_can_output_ndjson_records(): void
    {
        $this->fakeSalesforceService();

        $records = $this->records();

        $this->artisan('salesforce:interrogate --format=ndjson --limit=2')
            ->expectsOutput(json_encode($records[0], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->expectsOutput(json_encode($records[1], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->assertSuccessful();
    }

    public function test_interrogate_command_rejects_unknown_formats(): void
    {
        $this->fakeSalesforceService();

        $this->artisan('salesforce:interrogate --format=csv')
            ->expectsOutput('Invalid format. Use table, json, or ndjson.')
            ->assertFailed();
    }

    public function test_interrogate_command_can_output_account_linked_to_opportunity(): void
    {
        $this->fakeSalesforceService();

        $account = [
            'Id' => '001000000000001AAA',
            'Name' => 'Hartest Customer',
            'BillingCity' => 'Bury St Edmunds',
        ];

        $this->artisan('salesforce:interrogate --format=json --account-for-opportunity=006000000000001AAA')
            ->expectsOutput(json_encode([$account], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->assertSuccessful();
    }

    private function fakeSalesforceService(): void
    {
        $this->instance(SalesforceService::class, new class extends SalesforceService
        {
            public function fetchAllOpportunityFields(int $limit = 25): array
            {
                return [
                    'success' => true,
                    'records' => array_slice([
                        [
                            'Id' => '006000000000001AAA',
                            'Name' => 'Hartest Primary School',
                            'Project_Reference_Number__c' => '22600',
                            'Amount' => 1234.56,
                        ],
                        [
                            'Id' => '006000000000002AAA',
                            'Name' => 'Smethwick Independent Living Centre',
                            'Project_Reference_Number__c' => '22601',
                            'Amount' => 1694.61,
                        ],
                    ], 0, $limit),
                ];
            }

            public function fetchAccountForOpportunity(string $opportunityId): array
            {
                return [
                    'success' => true,
                    'opportunityId' => $opportunityId,
                    'accountId' => '001000000000001AAA',
                    'record' => [
                        'Id' => '001000000000001AAA',
                        'Name' => 'Hartest Customer',
                        'BillingCity' => 'Bury St Edmunds',
                    ],
                ];
            }
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        return [
            [
                'Id' => '006000000000001AAA',
                'Name' => 'Hartest Primary School',
                'Project_Reference_Number__c' => '22600',
                'Amount' => 1234.56,
            ],
            [
                'Id' => '006000000000002AAA',
                'Name' => 'Smethwick Independent Living Centre',
                'Project_Reference_Number__c' => '22601',
                'Amount' => 1694.61,
            ],
        ];
    }
}
