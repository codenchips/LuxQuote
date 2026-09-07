<?php

namespace Tests\Feature;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Http\Controllers\DocumentPackController;
use App\Http\Controllers\ProjectPdfController;
use App\Jobs\GeneratePdf;
use App\Models\DocumentPack;
use App\Models\PdfGeneration;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentPackPdfService;
use App\Services\PdfDownloadUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PdfGenerationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_queue_a_schedule_pdf(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();

        $response = $this->actingAs($admin)->postJson(route('projects.pdf.schedule.queue', [
            'project' => $project,
            'revision' => $project->active_revision_id,
            'include_datasheets' => true,
            'salesforce_upload' => true,
        ]));

        $response
            ->assertAccepted()
            ->assertJsonPath('status', PdfGeneration::StatusQueued)
            ->assertJsonPath('progress', 0);

        $generation = PdfGeneration::query()->sole();

        $this->assertSame($admin->id, $generation->user_id);
        $this->assertSame($project->id, $generation->project_id);
        $this->assertSame(PdfGeneration::TypeSchedule, $generation->type);
        $this->assertTrue($generation->parameters['include_datasheets']);
        $this->assertArrayNotHasKey('area_ids', $generation->parameters);
        Queue::assertPushedOn('pdf', GeneratePdf::class);
    }

    public function test_quote_queue_endpoint_enforces_quote_permissions(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('projects.pdf.quote.queue', [
                'project' => $project,
                'revision' => $project->active_revision_id,
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('pdf_generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_document_pack_queue_cannot_bypass_contained_quote_permissions(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $pack = DocumentPack::factory()->for($project)->create(['created_by' => $user->id]);
        $pack->items()->create([
            'role' => DocumentPackItemRole::Quote,
            'source_type' => DocumentPackItemSource::Generated,
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->postJson(route('projects.document-packs.queue', [
                'project' => $project,
                'documentPack' => $pack,
                'revision' => $project->active_revision_id,
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('pdf_generations', 0);
    }

    public function test_generation_status_is_scoped_to_the_requesting_user(): void
    {
        $owner = User::factory()->admin()->create();
        $otherUser = User::factory()->admin()->create();
        $project = Project::factory()->for($owner)->create();
        $generation = PdfGeneration::factory()->for($owner)->for($project)->create();

        $this->actingAs($otherUser)
            ->getJson(route('pdf-generations.show', $generation))
            ->assertForbidden();

        $this->actingAs($owner)
            ->getJson(route('pdf-generations.show', $generation))
            ->assertOk()
            ->assertJsonPath('id', $generation->uuid)
            ->assertJsonPath('status', PdfGeneration::StatusQueued);
    }

    public function test_failed_generation_can_be_requeued_by_its_authorized_owner(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $generation = PdfGeneration::factory()->for($admin)->for($project)->create([
            'status' => PdfGeneration::StatusFailed,
            'progress' => 100,
            'error_message' => 'The first attempt failed.',
            'completed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('pdf-generations.retry', $generation))
            ->assertAccepted()
            ->assertJsonPath('status', PdfGeneration::StatusQueued);

        $generation->refresh();
        $this->assertNull($generation->error_message);
        $this->assertNull($generation->completed_at);
        Queue::assertPushedOn('pdf', GeneratePdf::class);
    }

    public function test_job_records_a_successful_download_result(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $generation = PdfGeneration::factory()->for($admin)->for($project)->create([
            'type' => PdfGeneration::TypeSchedule,
            'parameters' => ['revision' => $project->active_revision_id],
        ]);

        $projectController = Mockery::mock(ProjectPdfController::class);
        $projectController->shouldReceive('schedule')
            ->once()
            ->withArgs(fn (Request $request, Project $queuedProject): bool => $request->boolean('pdf_delivery_link')
                && $request->attributes->get('queued_pdf_worker') === true
                && $queuedProject->is($project))
            ->andReturn(response()->json([
                'url' => 'https://example.test/pdf-downloads/token/file.pdf',
                'filename' => 'project-schedule.pdf',
                'token' => str_repeat('A', 48),
            ]));

        (new GeneratePdf($generation->id))->handle(
            $projectController,
            Mockery::mock(DocumentPackController::class),
            Mockery::mock(DocumentPackPdfService::class),
            Mockery::mock(PdfDownloadUrlService::class),
        );

        $generation->refresh();
        $this->assertSame(PdfGeneration::StatusCompleted, $generation->status);
        $this->assertSame(100, $generation->progress);
        $this->assertSame('project-schedule.pdf', $generation->result['filename']);
        $this->assertNotNull($generation->expires_at);
    }

    public function test_queued_schedule_with_all_areas_generates_successfully(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();

        $this->actingAs($admin)
            ->postJson(route('projects.pdf.schedule.queue', [
                'project' => $project,
                'revision' => $project->active_revision_id,
            ]))
            ->assertAccepted();

        $generation = PdfGeneration::query()->sole();
        $this->assertArrayNotHasKey('area_ids', $generation->parameters);

        $legacyParameters = $generation->parameters;
        $legacyParameters['area_ids'] = [];
        $generation->update(['parameters' => $legacyParameters]);

        (new GeneratePdf($generation->id))->handle(
            app(ProjectPdfController::class),
            app(DocumentPackController::class),
            app(DocumentPackPdfService::class),
            app(PdfDownloadUrlService::class),
        );

        $generation->refresh();
        $this->assertSame(PdfGeneration::StatusCompleted, $generation->status);
        $this->assertNotEmpty($generation->result['url'] ?? null);
        $this->assertNotEmpty($generation->result['filename'] ?? null);
    }

    public function test_terminal_job_failure_records_a_safe_retryable_error(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $generation = PdfGeneration::factory()->for($admin)->for($project)->create([
            'status' => PdfGeneration::StatusProcessing,
        ]);
        $job = new GeneratePdf($generation->id);

        $job->failed(new RuntimeException('The datasheet service could not be reached. Please try again.'));

        $generation->refresh();
        $this->assertSame(PdfGeneration::StatusFailed, $generation->status);
        $this->assertSame(100, $generation->progress);
        $this->assertSame('The datasheet service could not be reached. Please try again.', $generation->error_message);
    }

    public function test_non_transient_request_failure_is_recorded_without_retrying(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $generation = PdfGeneration::factory()->for($admin)->for($project)->create([
            'type' => PdfGeneration::TypeSchedule,
            'parameters' => ['revision' => $project->active_revision_id],
        ]);

        $projectController = Mockery::mock(ProjectPdfController::class);
        $projectController->shouldReceive('schedule')
            ->once()
            ->andThrow(new HttpException(422, 'The selected output is no longer valid.'));

        (new GeneratePdf($generation->id))->handle(
            $projectController,
            Mockery::mock(DocumentPackController::class),
            Mockery::mock(DocumentPackPdfService::class),
            Mockery::mock(PdfDownloadUrlService::class),
        );

        $generation->refresh();
        $this->assertSame(PdfGeneration::StatusFailed, $generation->status);
        $this->assertSame(1, $generation->attempt_count);
        $this->assertSame('The selected output is no longer valid.', $generation->error_message);
    }
}
