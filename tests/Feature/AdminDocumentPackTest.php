<?php

namespace Tests\Feature;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Enums\ProjectRevisionStatus;
use App\Filament\Resources\Projects\Pages\OutputProject;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use App\Services\DocumentPackPdfService;
use App\Services\ProjectSchedulePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class AdminDocumentPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_a_named_document_pack_with_ordered_items(): void
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $this->actingAs($admin);

        $component = Livewire::test(OutputProject::class, ['record' => $project->id]);
        $firstKey = array_key_first($component->get('documentPackItems'));

        $component
            ->set('documentPackName', 'Customer Quote Pack')
            ->set("documentPackItems.{$firstKey}.role", DocumentPackItemRole::Cover->value)
            ->set("documentPackUploads.{$firstKey}", UploadedFile::fake()->createWithContent('cover.pdf', $this->pdfWithText('Cover')))
            ->call('addDocumentPackItem', $firstKey);

        $secondKey = array_key_last($component->get('documentPackItems'));

        $component
            ->set("documentPackItems.{$secondKey}.role", DocumentPackItemRole::UnpricedSchedule->value)
            ->call('saveDocumentPack')
            ->assertHasNoErrors()
            ->assertNotified('Document pack saved');

        $pack = DocumentPack::where('project_id', $project->id)->firstOrFail();
        $this->assertSame('Customer Quote Pack', $pack->name);
        $this->assertSame($admin->id, $pack->created_by);
        $this->assertSame([
            DocumentPackItemRole::Cover,
            DocumentPackItemRole::UnpricedSchedule,
        ], $pack->items->pluck('role')->all());
        $this->assertSame([
            DocumentPackItemSource::Uploaded,
            DocumentPackItemSource::Generated,
        ], $pack->items->pluck('source_type')->all());

        $cover = $pack->items->first();
        $this->assertSame('cover.pdf', $cover->original_filename);
        Storage::disk('local')->assertExists($cover->file_path);
    }

    public function test_save_removes_blank_document_pack_blocks_and_saves_remaining_items(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $this->actingAs($admin);

        $component = Livewire::test(OutputProject::class, ['record' => $project->id]);
        $firstKey = array_key_first($component->get('documentPackItems'));

        $component
            ->set('documentPackName', 'Pack With Draft Block')
            ->call('addDocumentPackItem', $firstKey);

        $secondKey = array_key_last($component->get('documentPackItems'));

        $component
            ->set("documentPackItems.{$secondKey}.role", DocumentPackItemRole::UnpricedSchedule->value)
            ->call('saveDocumentPack')
            ->assertHasNoErrors()
            ->assertNotified('Document pack saved');

        $pack = DocumentPack::where('project_id', $project->id)->firstOrFail();

        $this->assertSame([DocumentPackItemRole::UnpricedSchedule], $pack->items->pluck('role')->all());
        $this->assertCount(1, $component->get('documentPackItems'));
    }

    public function test_save_removes_uploaded_document_pack_blocks_without_files_and_saves_remaining_items(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $this->actingAs($admin);

        $component = Livewire::test(OutputProject::class, ['record' => $project->id]);
        $firstKey = array_key_first($component->get('documentPackItems'));

        $component
            ->set('documentPackName', 'Pack Missing External File')
            ->set("documentPackItems.{$firstKey}.role", DocumentPackItemRole::Cover->value)
            ->call('addDocumentPackItem', $firstKey);

        $secondKey = array_key_last($component->get('documentPackItems'));

        $component
            ->set("documentPackItems.{$secondKey}.role", DocumentPackItemRole::UnpricedSchedule->value)
            ->call('saveDocumentPack')
            ->assertHasNoErrors()
            ->assertNotified('Document pack saved');

        $pack = DocumentPack::where('project_id', $project->id)->firstOrFail();

        $this->assertSame([DocumentPackItemRole::UnpricedSchedule], $pack->items->pluck('role')->all());
        $this->assertCount(1, $component->get('documentPackItems'));
    }

    public function test_saved_pack_can_be_reordered_and_reused_for_another_revision(): void
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $secondRevision = ProjectRevision::create([
            'project_id' => $project->id,
            'revision_number' => 1,
            'created_by' => $admin->id,
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
            'status' => ProjectRevisionStatus::Approved,
        ]);
        $pack = DocumentPack::factory()->for($project)->create([
            'created_by' => $admin->id,
            'name' => 'Reusable Pack',
        ]);
        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::Quote,
            'source_type' => DocumentPackItemSource::Generated,
            'sort_order' => 0,
        ]);
        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::UnpricedSchedule,
            'source_type' => DocumentPackItemSource::Generated,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin);
        $component = Livewire::test(OutputProject::class, ['record' => $project->id])
            ->set('outputTab', 'packs')
            ->set('generationRevisionId', $secondRevision->id);
        $secondKey = array_key_last($component->get('documentPackItems'));

        $component->call('sortDocumentPackItem', $secondKey, 0);

        $this->assertSame($secondKey, array_key_first($component->get('documentPackItems')));
        $component
            ->assertSet("documentPackItems.{$secondKey}.role", DocumentPackItemRole::UnpricedSchedule->value)
            ->assertSeeHtml('wire:key="document-pack-role-'.$secondKey.'"')
            ->call('saveDocumentPack')
            ->assertHasNoErrors();

        $this->assertSame([
            DocumentPackItemRole::UnpricedSchedule,
            DocumentPackItemRole::Quote,
        ], $pack->fresh()->items->pluck('role')->all());
        $this->assertSame($secondRevision->id, $component->get('generationRevisionId'));
        $this->assertStringContainsString(
            'revision='.$secondRevision->id,
            $component->instance()->getDocumentPackDownloadUrl(),
        );
    }

    public function test_user_without_manage_permission_cannot_mutate_document_packs(): void
    {
        $group = PermissionGroup::create([
            'name' => 'Output Viewer',
            'slug' => 'output-viewer',
            'description' => null,
            'is_system' => false,
        ]);
        $group->permissions()->attach(Permission::where('key', 'output.view')->firstOrFail());

        $user = User::factory()->create(['permission_group_id' => $group->id]);
        $project = Project::factory()->for($user)->create();
        $this->actingAs($user);

        $this->assertFalse($user->can('output.manage-document-packs'));

        $failed = false;

        try {
            Livewire::test(OutputProject::class, ['record' => $project->id])
                ->call('newDocumentPack');
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'The server-side document pack mutation should be denied.');
    }

    public function test_qpdf_merges_uploaded_documents_in_saved_order(): void
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create(['reference_number' => 'PACK-001']);
        $pack = DocumentPack::factory()->for($project)->create([
            'created_by' => $admin->id,
            'name' => 'Tender Pack',
        ]);

        Storage::disk('local')->put('tests/cover.pdf', $this->pdfWithText('Cover'));
        Storage::disk('local')->put('tests/legal.pdf', $this->pdfWithText('Legal'));

        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::Cover,
            'source_type' => DocumentPackItemSource::Uploaded,
            'sort_order' => 0,
            'file_disk' => 'local',
            'file_path' => 'tests/cover.pdf',
            'original_filename' => 'cover.pdf',
        ]);
        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::Legal,
            'source_type' => DocumentPackItemSource::Uploaded,
            'sort_order' => 1,
            'file_disk' => 'local',
            'file_path' => 'tests/legal.pdf',
            'original_filename' => 'legal.pdf',
        ]);

        $generated = app(DocumentPackPdfService::class)->generate($pack, $project->activeRevision, $admin);

        $process = new Process(['qpdf', '--show-npages', $generated['path']]);
        $process->mustRun();

        $this->assertSame('2', trim($process->getOutput()));
        $this->assertSame('PACK-001-Tender-Pack-P0-document-pack.pdf', $generated['filename']);

        File::delete($generated['path']);
    }

    public function test_generated_items_use_the_revision_selected_at_generation_time(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $secondRevision = ProjectRevision::create([
            'project_id' => $project->id,
            'revision_number' => 1,
            'created_by' => $admin->id,
        ]);
        $pack = DocumentPack::factory()->for($project)->create(['created_by' => $admin->id]);
        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::UnpricedSchedule,
            'source_type' => DocumentPackItemSource::Generated,
        ]);

        $projectPdfService = new class extends ProjectSchedulePdfService
        {
            /** @var array<int, int> */
            public array $revisionIds = [];

            public function content(Project $project, ProjectRevision $revision): string
            {
                $this->revisionIds[] = $revision->id;

                return AdminDocumentPackTest::makePdf('Revision '.$revision->revision_number);
            }
        };

        $service = new DocumentPackPdfService($projectPdfService);
        $generated = $service->generate($pack, $secondRevision, $admin);

        $this->assertSame([$secondRevision->id], $projectPdfService->revisionIds);
        File::delete($generated['path']);
    }

    public function test_invalid_external_pdf_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $path = storage_path('app/private/invalid-document-pack.pdf');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'not a pdf');

        try {
            app(DocumentPackPdfService::class)->assertValidUploadedPdf($path);
        } finally {
            File::delete($path);
        }
    }

    public function test_document_pack_download_rejects_a_pack_from_another_project(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $otherProject = Project::factory()->for($admin)->create();
        $otherPack = DocumentPack::factory()->for($otherProject)->create(['created_by' => $admin->id]);
        $this->actingAs($admin);

        $this->get(route('projects.document-packs.download', [
            'project' => $project,
            'documentPack' => $otherPack,
            'revision' => $project->active_revision_id,
        ]))->assertNotFound();
    }

    public function test_document_pack_cannot_bypass_quote_permissions(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $pack = DocumentPack::factory()->for($project)->create(['created_by' => $user->id]);
        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::Quote,
            'source_type' => DocumentPackItemSource::Generated,
        ]);
        $this->actingAs($user);

        $this->assertTrue($user->can('output.produce-document-packs'));
        $this->assertFalse($user->can('output.produce-quote'));

        $component = Livewire::test(OutputProject::class, ['record' => $project->id])
            ->set('outputTab', 'packs');
        $this->assertNull($component->instance()->getDocumentPackDownloadUrl());
        $component->assertSee('This pack contains a document you do not have permission to generate.');

        $this->get(route('projects.document-packs.download', [
            'project' => $project,
            'documentPack' => $pack,
            'revision' => $project->active_revision_id,
        ]))->assertForbidden();
    }

    public function test_pack_with_quote_cannot_be_generated_until_selected_revision_is_approved(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $pack = DocumentPack::factory()->for($project)->create(['created_by' => $admin->id]);
        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::Quote,
            'source_type' => DocumentPackItemSource::Generated,
        ]);
        $this->actingAs($admin);

        $component = Livewire::test(OutputProject::class, ['record' => $project->id])
            ->set('outputTab', 'packs');

        $this->assertNull($component->instance()->getDocumentPackDownloadUrl());
        $component
            ->assertSee('Approve the quote for P0 before generating this pack.')
            ->assertSeeHtml('<button data-testid="generate-document-pack" type="button" disabled');

        $project->activeRevision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $this->assertNotNull($component->instance()->getDocumentPackDownloadUrl());
    }

    public function test_saved_document_pack_downloads_as_one_pdf_and_is_logged(): void
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create(['reference_number' => 'DOWNLOAD-001']);
        $pack = DocumentPack::factory()->for($project)->create([
            'created_by' => $admin->id,
            'name' => 'Customer Pack',
        ]);
        Storage::disk('local')->put('tests/download-cover.pdf', $this->pdfWithText('Download Cover'));
        DocumentPackItem::factory()->for($pack)->create([
            'role' => DocumentPackItemRole::Cover,
            'source_type' => DocumentPackItemSource::Uploaded,
            'file_disk' => 'local',
            'file_path' => 'tests/download-cover.pdf',
            'original_filename' => 'cover.pdf',
        ]);
        $this->actingAs($admin);

        $response = $this->get(route('projects.document-packs.download', [
            'project' => $project,
            'documentPack' => $pack,
            'revision' => $project->active_revision_id,
        ]));

        $response->assertOk()->assertDownload('DOWNLOAD-001-Customer-Pack-P0-document-pack.pdf');

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action_type' => 'document_pack.generated',
            'revision_number' => 0,
        ]);

        File::delete($response->baseResponse->getFile()->getPathname());
    }

    public static function makePdf(string $text): string
    {
        $escapedText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 18 Tf 72 720 Td ({$escapedText}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    private function pdfWithText(string $text): string
    {
        return self::makePdf($text);
    }
}
