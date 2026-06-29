<?php

namespace Tests\Feature;

use App\Enums\ProjectRevisionStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectSchedulePdfService;
use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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

        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), 'IsClosed = false')
            && str_contains((string) ($request->data()['q'] ?? ''), 'IsWon = false')
            && str_contains((string) ($request->data()['q'] ?? ''), "Name LIKE '%Hartest%'"));
    }

    public function test_opportunity_reference_search_returns_full_project_names(): void
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

        $options = app(SalesforceService::class)->searchOpportunitiesByReference('226');

        $this->assertSame([
            '006000000000001AAA' => '22600 — Hartest Primary School',
        ], $options);

        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['q'] ?? ''), 'IsClosed = false')
            && str_contains((string) ($request->data()['q'] ?? ''), 'IsWon = false')
            && str_contains((string) ($request->data()['q'] ?? ''), "Project_Reference_Number__c LIKE '%226%'"));
    }

    public function test_opportunity_listing_excludes_closed_and_won_projects(): void
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
                $query = (string) ($request->data()['q'] ?? '');

                if (str_contains($query, 'COUNT()')) {
                    return Http::response(['totalSize' => 1]);
                }

                return Http::response([
                    'records' => [
                        [
                            'Id' => '006000000000001AAA',
                            'Name' => 'Open Project',
                            'Amount' => 100,
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $records = app(SalesforceService::class)->getOpportunities(
            search: 'Open',
            fields: ['Id', 'Name', 'Amount'],
        );

        $this->assertSame(1, $records->total());
        $this->assertSame('Open Project', $records->items()[0]['Name']);

        $queries = Http::recorded()
            ->map(fn (array $record): string => (string) ($record[0]->data()['q'] ?? ''))
            ->filter();

        $this->assertTrue($queries->every(fn (string $query): bool => str_contains($query, 'IsClosed = false')
            && str_contains($query, 'IsWon = false')));
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

    public function test_generated_schedule_pdf_is_uploaded_to_salesforce_opportunity(): void
    {
        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()
            ->for($admin)
            ->create([
                'reference_number' => 'SCH-001',
                'salesforce_project' => true,
                'salesforce_id' => '006000000000001AAA',
            ]);

        $this->instance(ProjectSchedulePdfService::class, $this->fakePdfService(
            scheduleFilename: 'lighting-schedule-SCH-001-P0-20260612-103000.pdf',
            quoteFilename: 'lighting-quote-SCH-001-P0-20260612-103000.pdf',
            responseBody: 'fake schedule pdf',
        ));

        $this->fakeSuccessfulSalesforcePdfUpload();

        $response = $this->get(route('projects.pdf.schedule', [
            'project' => $project,
            'revision' => $project->active_revision_id,
            'salesforce_upload' => true,
        ]));

        $response
            ->assertOk()
            ->assertSee('fake schedule pdf')
            ->assertSessionHas('filament.notifications');

        $this->assertContentVersionUploadWasSent('lighting-schedule-SCH-001-P0-20260612-103000.pdf');
        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action_type' => 'salesforce_pdf.uploaded',
            'revision_number' => 0,
        ]);
    }

    public function test_generated_quote_pdf_is_uploaded_to_salesforce_opportunity(): void
    {
        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()
            ->for($admin)
            ->create([
                'reference_number' => 'QT-001',
                'salesforce_project' => true,
                'salesforce_id' => '006000000000001AAA',
            ]);

        $project->activeRevision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $this->instance(ProjectSchedulePdfService::class, $this->fakePdfService(
            scheduleFilename: 'lighting-schedule-QT-001-P0-20260612-103000.pdf',
            quoteFilename: 'lighting-quote-QT-001-P0-20260612-103000.pdf',
            responseBody: 'fake quote pdf',
        ));

        $this->fakeSuccessfulSalesforcePdfUpload();

        $response = $this->get(route('projects.pdf.quote', [
            'project' => $project,
            'revision' => $project->active_revision_id,
            'salesforce_upload' => true,
        ]));

        $response
            ->assertOk()
            ->assertSee('fake quote pdf')
            ->assertSessionHas('filament.notifications');

        $this->assertContentVersionUploadWasSent('lighting-quote-QT-001-P0-20260612-103000.pdf');
        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action_type' => 'quote_pdf.generated',
            'revision_number' => 0,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action_type' => 'salesforce_pdf.uploaded',
            'revision_number' => 0,
        ]);
    }

    public function test_salesforce_upload_exception_does_not_block_generated_pdf_response(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()
            ->for($admin)
            ->create([
                'reference_number' => 'SCH-FAIL',
                'salesforce_project' => true,
                'salesforce_id' => '006000000000001AAA',
            ]);

        $this->instance(ProjectSchedulePdfService::class, $this->fakePdfService(
            scheduleFilename: 'lighting-schedule-SCH-FAIL-P0-20260612-103000.pdf',
            quoteFilename: 'lighting-quote-SCH-FAIL-P0-20260612-103000.pdf',
            responseBody: 'fake schedule pdf despite upload failure',
        ));

        $this->instance(SalesforceService::class, new class
        {
            public function uploadPdf(Project $project, string $pdfContent, string $filename): array
            {
                throw new RuntimeException('Production Salesforce configuration is invalid.');
            }
        });

        $response = $this->get(route('projects.pdf.schedule', [
            'project' => $project,
            'revision' => $project->active_revision_id,
            'salesforce_upload' => true,
        ]));

        $response
            ->assertOk()
            ->assertSee('fake schedule pdf despite upload failure')
            ->assertSessionHas('filament.notifications');

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action_type' => 'schedule_pdf.generated',
            'revision_number' => 0,
        ]);
    }

    public function test_viewing_generated_schedule_pdf_does_not_upload_to_salesforce_without_explicit_flag(): void
    {
        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()
            ->for($admin)
            ->create([
                'reference_number' => 'SCH-VIEW',
                'salesforce_project' => true,
                'salesforce_id' => '006000000000001AAA',
            ]);

        $this->instance(ProjectSchedulePdfService::class, $this->fakePdfService(
            scheduleFilename: 'lighting-schedule-SCH-VIEW-P0-20260612-103000.pdf',
            quoteFilename: 'lighting-quote-SCH-VIEW-P0-20260612-103000.pdf',
            responseBody: 'plain schedule view',
        ));

        $this->fakeSuccessfulSalesforcePdfUpload();

        $response = $this->get(route('projects.pdf.schedule', [
            'project' => $project,
            'revision' => $project->active_revision_id,
        ]));

        $response
            ->assertOk()
            ->assertSee('plain schedule view')
            ->assertSessionMissing('filament.notifications');

        $this->assertDatabaseMissing('activity_logs', [
            'project_id' => $project->id,
            'action_type' => 'salesforce_pdf.uploaded',
        ]);

        $this->assertContentVersionUploadWasNotSent();
    }

    public function test_viewing_generated_quote_pdf_does_not_upload_to_salesforce_without_explicit_flag(): void
    {
        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()
            ->for($admin)
            ->create([
                'reference_number' => 'QT-VIEW',
                'salesforce_project' => true,
                'salesforce_id' => '006000000000001AAA',
            ]);

        $project->activeRevision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $this->instance(ProjectSchedulePdfService::class, $this->fakePdfService(
            scheduleFilename: 'lighting-schedule-QT-VIEW-P0-20260612-103000.pdf',
            quoteFilename: 'lighting-quote-QT-VIEW-P0-20260612-103000.pdf',
            responseBody: 'plain quote view',
        ));

        $this->fakeSuccessfulSalesforcePdfUpload();

        $response = $this->get(route('projects.pdf.quote', [
            'project' => $project,
            'revision' => $project->active_revision_id,
        ]));

        $response
            ->assertOk()
            ->assertSee('plain quote view')
            ->assertSessionMissing('filament.notifications');

        $this->assertDatabaseMissing('activity_logs', [
            'project_id' => $project->id,
            'action_type' => 'salesforce_pdf.uploaded',
        ]);

        $this->assertContentVersionUploadWasNotSent();
    }

    public function test_pdf_filenames_include_document_title_reference_revision_and_timestamp(): void
    {
        $this->travelTo('2026-06-12 10:30:45');

        $project = Project::factory()
            ->for(User::factory())
            ->create(['reference_number' => 'REF 123']);

        $service = app(ProjectSchedulePdfService::class);

        $this->assertSame(
            'Lighting-Schedule-REF-123-P0-20260612-103045.pdf',
            $service->filename($project, $project->activeRevision),
        );

        $this->assertSame(
            'Lighting-Quote-REF-123-P0-20260612-103045.pdf',
            $service->quoteFilename($project, $project->activeRevision),
        );
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

    private function assertContentVersionUploadWasNotSent(): void
    {
        $requests = Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->filter(fn (Request $request): bool => $request->method() === 'POST'
                && str_contains($request->url(), '/services/data/v65.0/sobjects/ContentVersion'));

        $this->assertCount(0, $requests);
    }

    private function fakeSuccessfulSalesforcePdfUpload(): void
    {
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
    }

    private function fakePdfService(string $scheduleFilename, string $quoteFilename, string $responseBody): object
    {
        return new class($scheduleFilename, $quoteFilename, $responseBody)
        {
            public function __construct(
                private readonly string $scheduleFilename,
                private readonly string $quoteFilename,
                private readonly string $responseBody,
            ) {}

            public function filename(Project $project, $revision): string
            {
                return $this->scheduleFilename;
            }

            public function quoteFilename(Project $project, $revision): string
            {
                return $this->quoteFilename;
            }

            public function builder(Project $project, $revision): object
            {
                return $this->fakeBuilder();
            }

            public function quoteBuilder(Project $project, $revision): object
            {
                return $this->fakeBuilder();
            }

            public function contentFromBuilder(object $builder): string
            {
                return '%PDF-1.4 test content';
            }

            private function fakeBuilder(): object
            {
                return new class($this->responseBody)
                {
                    public function __construct(private readonly string $responseBody) {}

                    public function inline(string $filename): self
                    {
                        return $this;
                    }

                    public function toResponse($request)
                    {
                        return response($this->responseBody);
                    }
                };
            }
        };
    }
}
