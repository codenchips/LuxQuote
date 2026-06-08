<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesforceSchedulePdfUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_opportunity_search_returns_salesforce_project_options(): void
    {
        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
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
    }

    public function test_schedule_pdf_upload_creates_a_salesforce_file_on_the_opportunity(): void
    {
        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);

        $project = Project::factory()
            ->for(User::factory())
            ->create([
                'salesforce_project' => true,
                'salesforce_id' => '006000000000001AAA',
            ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/query/')) {
                $query = (string) ($request->data()['q'] ?? '');

                if (str_contains($query, 'FROM ContentDocumentLink')) {
                    return Http::response(['records' => []]);
                }

                if (str_contains($query, 'FROM ContentVersion')) {
                    return Http::response([
                        'records' => [
                            ['ContentDocumentId' => '069000000000001AAA'],
                        ],
                    ]);
                }
            }

            if (str_contains($request->url(), '/services/data/v65.0/sobjects/ContentVersion')) {
                return Http::response(['id' => '068000000000001AAA'], 201);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->uploadSchedulePdf(
            project: $project,
            pdfContent: '%PDF-1.4 test content',
            filename: 'schedule-22600-R3.pdf',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('068000000000001AAA', $result['contentVersionId']);
        $this->assertSame('069000000000001AAA', $result['contentDocumentId']);
        $this->assertSame(
            'https://example.my.salesforce.com/lightning/r/ContentDocument/069000000000001AAA/view',
            $result['url'],
        );

        $this->assertContentVersionUploadWasSent('schedule-22600-R3.pdf');
    }

    public function test_schedule_pdf_upload_creates_a_new_version_when_file_already_exists(): void
    {
        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);

        $project = Project::factory()
            ->for(User::factory())
            ->create([
                'salesforce_project' => true,
                'salesforce_id' => '006000000000001AAA',
            ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/query/')) {
                return Http::response([
                    'records' => [
                        ['ContentDocumentId' => '069000000000002AAA'],
                    ],
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/sobjects/ContentVersion')) {
                return Http::response(['id' => '068000000000002AAA'], 201);
            }

            return Http::response([], 500);
        });

        $result = app(SalesforceService::class)->uploadSchedulePdf(
            project: $project,
            pdfContent: '%PDF-1.4 test content',
            filename: 'schedule-22600-R3.pdf',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('068000000000002AAA', $result['contentVersionId']);
        $this->assertSame('069000000000002AAA', $result['contentDocumentId']);
        $this->assertSame(
            'https://example.my.salesforce.com/lightning/r/ContentDocument/069000000000002AAA/view',
            $result['url'],
        );

        $this->assertContentVersionUploadWasSent('schedule-22600-R3.pdf');
    }

    private function assertContentVersionUploadWasSent(string $filename): void
    {
        $requests = Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn (Request $request): bool => $request->method() === 'POST'
                && str_contains($request->url(), '/services/data/v65.0/sobjects/ContentVersion'));

        $this->assertCount(1, $requests);
        $this->assertTrue($requests->first()->isMultipart());
        $this->assertTrue($requests->first()->hasFile('VersionData', filename: $filename));
    }
}
