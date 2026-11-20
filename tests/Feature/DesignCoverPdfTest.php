<?php

namespace Tests\Feature;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Enums\ProjectRevisionStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\Projects\Pages\OutputProject;
use App\Models\ActivityLog;
use App\Models\DocumentPack;
use App\Models\DocumentPackTemplate;
use App\Models\PdfGeneration;
use App\Models\PermissionGroup;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentPackPdfService;
use App\Services\ProjectDesignCoverPdfService;
use App\Services\SalesforcePdfUploadTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DesignCoverPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_design_cover_renders_two_pages_with_project_details_and_current_date(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 19));
        $admin = User::factory()->admin()->create(['name' => 'Alexander Lawlor']);
        $project = Project::factory()->for($admin)->create(['name' => 'Ayrshire College', 'reference_number' => '24988', 'date' => '2020-01-01']);
        $directory = storage_path('app/private/design-cover-reference');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/preview.pdf';
        File::put($path, app(ProjectDesignCoverPdfService::class)->content($project));
        $this->assertSame(2, $this->pageCount($path));
        $text = $this->pdfText($path);
        $this->assertStringContainsString('Alexander Lawlor', $text);
        $this->assertStringContainsString('19/11/2026', $text);
        $this->assertSame(2, substr_count($text, 'Ayrshire College'));
        $this->assertSame(2, substr_count($text, '24988'));
        $this->assertStringContainsString("end user's requirements.", $text);
        $this->assertStringNotContainsString('[Project', $text);
        $this->assertStringNotContainsString('Page 1 of', $text);
    }

    public function test_schedule_option_is_on_by_default_and_queue_preserves_it(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $this->actingAs($admin);
        $component = Livewire::test(OutputProject::class, ['record' => $project->id])
            ->assertSet('includeScheduleDesignCover', true)
            ->assertSee('Cover Page');
        $this->assertStringContainsString('include_design_cover=1', $component->instance()->getSchedulePdfUrl());
        $component->set('includeScheduleDesignCover', false);
        $this->assertStringContainsString('include_design_cover=0', $component->instance()->getSchedulePdfUrl());

        foreach ([false, true] as $enabled) {
            $this->postJson(route('projects.pdf.schedule.queue', ['project' => $project, 'include_design_cover' => $enabled]))->assertAccepted();
            $this->assertSame($enabled, PdfGeneration::latest('id')->firstOrFail()->parameters['include_design_cover']);
        }
    }

    public function test_schedule_cover_precedes_schedule_and_is_recorded_for_redownload(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create(['name' => 'Cover test', 'reference_number' => 'COVER-01']);
        $this->actingAs($admin);
        $pageCounts = [];

        foreach ([false, true] as $enabled) {
            $response = $this->get(route('projects.pdf.schedule', ['project' => $project, 'include_design_cover' => $enabled, 'salesforce_upload' => false]));
            $response->assertOk();
            $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
            $path = $response->baseResponse->getFile()->getPathname();
            $pageCounts[] = $this->pageCount($path);
            if ($enabled) {
                $firstTwo = new Process(['qpdf', $path, '--pages', '.', '1-2', '--', $path.'.cover.pdf']);
                $firstTwo->mustRun();
                $this->assertStringContainsString('Scheme statement', $this->pdfText($path.'.cover.pdf'));
                File::delete($path.'.cover.pdf');
            }
            File::delete($path);
            $log = ActivityLog::where('project_id', $project->id)->where('action_type', 'schedule_pdf.generated')->latest('id')->firstOrFail();
            $this->assertSame($enabled, $log->payload['include_design_cover']);
            $rows = Livewire::test(OutputProject::class, ['record' => $project->id])->instance()->outputHistoryRows();
            $this->assertNotEmpty($rows);
            $this->assertStringContainsString('include_design_cover='.($enabled ? '1' : '0'), $rows[0]['regenerate_url']);
        }
        $this->assertSame($pageCounts[0] + 2, $pageCounts[1]);
    }

    public function test_design_cover_can_be_saved_previewed_and_generated_in_document_packs(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $this->actingAs($admin);
        $component = Livewire::test(OutputProject::class, ['record' => $project->id])->set('documentPackName', 'Design pack');
        $key = array_key_first($component->get('documentPackItems'));
        $component->set("documentPackItems.{$key}.role", DocumentPackItemRole::DesignCover->value)
            ->call('saveDocumentPack')->assertHasNoErrors();
        $pack = DocumentPack::where('project_id', $project->id)->firstOrFail();
        $this->assertSame(DocumentPackItemSource::Template, $pack->items->sole()->source_type);
        $result = app(DocumentPackPdfService::class)->generate($pack, $project->activeRevision, $admin);
        $this->assertSame(2, $this->pageCount($result['path']));
        File::delete($result['path']);
        $this->get(route('projects.document-packs.design-cover.file', ['project' => $project]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_long_project_names_keep_the_cover_to_two_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create(['name' => str_repeat('Long Project Name ', 14)]);
        $path = storage_path('app/private/design-cover-reference/long-name.pdf');
        File::put($path, app(ProjectDesignCoverPdfService::class)->content($project));
        $this->assertSame(2, $this->pageCount($path));
        $this->assertStringContainsString("end user's requirements.", $this->pdfText($path));
        File::delete($path);
    }

    public function test_design_cover_can_be_reused_in_document_pack_templates(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $this->actingAs($admin);
        $component = Livewire::test(OutputProject::class, ['record' => $project->id]);
        $key = array_key_first($component->get('documentPackItems'));
        $component->set("documentPackItems.{$key}.role", DocumentPackItemRole::DesignCover->value)
            ->set('documentPackTemplateName', 'Design cover template')
            ->set('documentPackTemplateVisibilityTarget', ProjectVisibility::Open->value)
            ->call('saveDocumentPackAsTemplate')->assertHasNoErrors();
        $template = DocumentPackTemplate::query()->sole();
        $this->assertSame(DocumentPackItemRole::DesignCover, $template->items->sole()->role);
        $otherProject = Project::factory()->for($admin)->create();
        $reused = Livewire::test(OutputProject::class, ['record' => $otherProject->id])
            ->set('selectedDocumentPackTemplateId', $template->id)
            ->call('useSelectedDocumentPackTemplate')->assertHasNoErrors();
        $this->assertSame(DocumentPackItemRole::DesignCover->value, collect($reused->get('documentPackItems'))->sole()['role']);
    }

    public function test_quotes_ignore_the_schedule_design_cover_option(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $project->activeRevision->update(['validated' => true, 'validated_at' => now(), 'validated_by' => $admin->id, 'status' => ProjectRevisionStatus::Approved]);
        $this->mock(ProjectDesignCoverPdfService::class)->shouldNotReceive('content')->shouldNotReceive('prependTo');
        $response = $this->actingAs($admin)->get(route('projects.pdf.quote', ['project' => $project, 'include_design_cover' => true, 'salesforce_upload' => false]));
        $response->assertOk();
        File::delete($response->baseResponse->getFile()->getPathname());
    }

    public function test_cover_preview_requires_document_pack_management(): void
    {
        $group = PermissionGroup::create(['name' => 'Cover viewer', 'slug' => 'cover-viewer', 'is_system' => false]);
        $user = User::factory()->create(['permission_group_id' => $group->id]);
        $project = Project::factory()->for($user)->create();
        $this->actingAs($user)->get(route('projects.document-packs.design-cover.file', ['project' => $project]))->assertForbidden();
    }

    public function test_cover_changes_schedule_fingerprint_but_not_quote_fingerprint(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $tracker = app(SalesforcePdfUploadTracker::class);
        $this->assertNotSame(
            $tracker->fingerprint($project, $project->activeRevision, 'schedule', false),
            $tracker->fingerprint($project, $project->activeRevision, 'schedule', false, includeDesignCover: true),
        );
        $this->assertSame(
            $tracker->fingerprint($project, $project->activeRevision, 'quote', true),
            $tracker->fingerprint($project, $project->activeRevision, 'quote', true, includeDesignCover: true),
        );
    }

    private function pageCount(string $path): int
    {
        $process = new Process(['qpdf', '--show-npages', $path]);
        $process->mustRun();

        return (int) trim($process->getOutput());
    }

    private function pdfText(string $path): string
    {
        $process = new Process(['gs', '-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=txtwrite', '-sOutputFile=-', $path]);
        $process->mustRun();

        return preg_replace('/\s+/', ' ', $process->getOutput());
    }
}
