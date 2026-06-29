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
