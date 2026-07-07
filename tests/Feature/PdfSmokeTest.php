<?php

namespace Tests\Feature;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Enums\ProjectLineType;
use App\Enums\ProjectRevisionStatus;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PdfSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_environment_diagnostic_renders_a_probe_pdf(): void
    {
        $this->artisan('app:diagnose-pdf-environment')
            ->expectsOutput('PDF render succeeded.')
            ->assertExitCode(0);
    }

    public function test_production_health_check_exercises_the_pdf_runtime(): void
    {
        $this->artisan('app:production-health-check')
            ->expectsOutput('Health check passed.')
            ->assertExitCode(0);
    }

    public function test_production_pdf_only_health_check_exercises_the_pdf_runtime(): void
    {
        $this->artisan('app:production-health-check --pdf-only')
            ->expectsOutput('Health check passed.')
            ->assertExitCode(0);
    }

    public function test_schedule_pdf_download_renders_and_merges_the_standard_legal_page(): void
    {
        $admin = User::factory()->admin()->create();
        $project = $this->projectWithLines($admin, 'PDF-SMOKE-SCHEDULE');

        $this->actingAs($admin);

        $response = $this->get(route('projects.pdf.schedule', [
            'project' => $project,
            'revision' => $project->active_revision_id,
        ]));

        $response->assertOk()->assertDownload();

        $path = $this->downloadedPdfPath($response->baseResponse);
        $this->assertValidPdf($path);
        $this->assertPdfPageCountAtLeast($path, 2);
        File::delete($path);
    }

    public function test_quote_pdf_download_renders_and_merges_the_standard_legal_page(): void
    {
        $admin = User::factory()->admin()->create();
        $project = $this->projectWithLines($admin, 'PDF-SMOKE-QUOTE');
        $project->activeRevision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $this->actingAs($admin);

        $response = $this->get(route('projects.pdf.quote', [
            'project' => $project,
            'revision' => $project->active_revision_id,
        ]));

        $response->assertOk()->assertDownload();

        $path = $this->downloadedPdfPath($response->baseResponse);
        $this->assertValidPdf($path);
        $this->assertPdfPageCountAtLeast($path, 2);
        File::delete($path);
    }

    public function test_document_pack_download_renders_generated_documents_and_merges_with_qpdf(): void
    {
        $admin = User::factory()->admin()->create();
        $project = $this->projectWithLines($admin, 'PDF-SMOKE-PACK');
        $project->activeRevision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
            'status' => ProjectRevisionStatus::Approved,
        ]);
        $pack = DocumentPack::factory()->for($project)->create([
            'created_by' => $admin->id,
            'name' => 'Smoke Pack',
        ]);

        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::UnpricedSchedule,
            'source_type' => DocumentPackItemSource::Generated,
            'sort_order' => 0,
        ]);
        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::StandardLegalPage,
            'source_type' => DocumentPackItemSource::Template,
            'sort_order' => 1,
        ]);
        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::Quote,
            'source_type' => DocumentPackItemSource::Generated,
            'sort_order' => 2,
        ]);

        $this->actingAs($admin);

        $response = $this->get(route('projects.document-packs.download', [
            'project' => $project,
            'documentPack' => $pack,
            'revision' => $project->active_revision_id,
        ]));

        $response->assertOk()->assertDownload('PDF-SMOKE-PACK-Smoke-Pack-P0-document-pack.pdf');

        $path = $this->downloadedPdfPath($response->baseResponse);
        $this->assertValidPdf($path);
        $this->assertPdfPageCountAtLeast($path, 3);
        File::delete($path);
    }

    private function projectWithLines(User $user, string $referenceNumber): Project
    {
        $project = Project::factory()->for($user)->create([
            'name' => 'PDF Smoke Project',
            'reference_number' => $referenceNumber,
            'customer_name' => 'Smoke Test Customer',
            'contractor' => 'Smoke Test Contractor',
            'value' => 245.00,
        ]);

        $area = $project->activeRevision->areas()->firstOrFail();
        $area->lines()->createMany([
            [
                'code' => 'AST110NW',
                'ref' => 'A1',
                'description' => 'ASTRO 10W - 4000K',
                'qty' => 2,
                'type' => ProjectLineType::Standard->value,
                'unit_price' => 27.50,
                'notes' => 'Smoke test schedule line.',
                'sort_order' => 0,
            ],
            [
                'code' => 'XCP5K67RGB',
                'ref' => 'A2',
                'description' => 'Pro Outdoor IP67 12V 5m RGB LED Strip Kit',
                'qty' => 1,
                'type' => ProjectLineType::Custom->value,
                'unit_price' => 190.00,
                'notes' => null,
                'sort_order' => 1,
            ],
        ]);

        return $project->refresh();
    }

    private function downloadedPdfPath(mixed $response): string
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response);

        return $response->getFile()->getPathname();
    }

    private function assertValidPdf(string $path): void
    {
        $process = new Process([(string) config('document-packs.qpdf_binary', 'qpdf'), '--check', $path]);
        $process->mustRun();

        $this->assertStringContainsString('No syntax or stream encoding errors found', $process->getOutput());
    }

    private function assertPdfPageCountAtLeast(string $path, int $minimumPages): void
    {
        $process = new Process([(string) config('document-packs.qpdf_binary', 'qpdf'), '--show-npages', $path]);
        $process->mustRun();

        $this->assertGreaterThanOrEqual($minimumPages, (int) trim($process->getOutput()));
    }
}
