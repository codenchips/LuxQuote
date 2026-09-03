<?php

namespace Tests\Feature;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\Projects\Pages\OutputProject;
use App\Models\DocumentPack;
use App\Models\DocumentPackTemplate;
use App\Models\DocumentPackTemplateItem;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Project;
use App\Models\ResourceFile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDocumentPackTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_ordered_pack_as_template_with_an_independent_resource_snapshot(): void
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $resource = ResourceFile::factory()->for($admin, 'uploader')->create([
            'display_name' => 'Company Introduction',
            'original_filename' => 'company-introduction.pdf',
        ]);
        Storage::disk(ResourceFile::Disk)->put($resource->file_path, $this->pdfWithText('Company Introduction'));
        $this->actingAs($admin);

        $component = Livewire::test(OutputProject::class, ['record' => $project->id])
            ->set('outputTab', 'packs');
        $resourceKey = array_key_first($component->get('documentPackItems'));
        $component
            ->call('openDocumentPackResourcePicker', $resourceKey)
            ->call('addDocumentPackResource', $resource->id)
            ->call('addDocumentPackItem', $resourceKey);
        $scheduleKey = array_key_last($component->get('documentPackItems'));
        $component
            ->call('selectDocumentPackRole', $scheduleKey, DocumentPackItemRole::UnpricedSchedule->value)
            ->call('addDocumentPackItem', $scheduleKey);
        $quoteKey = array_key_last($component->get('documentPackItems'));
        $component
            ->call('selectDocumentPackRole', $quoteKey, DocumentPackItemRole::Quote->value)
            ->set('documentPackTemplateName', 'Standard Customer Pack')
            ->set('documentPackTemplateVisibilityTarget', ProjectVisibility::Open->value)
            ->call('saveDocumentPackAsTemplate')
            ->assertHasNoErrors()
            ->assertNotified('Document pack template saved');

        $template = DocumentPackTemplate::query()->with('items')->sole();
        $this->assertSame('Standard Customer Pack', $template->name);
        $this->assertSame(ProjectVisibility::Open, $template->visibility);
        $this->assertSame($admin->id, $template->user_id);
        $this->assertSame([
            DocumentPackItemRole::CustomPdf,
            DocumentPackItemRole::UnpricedSchedule,
            DocumentPackItemRole::Quote,
        ], $template->items->pluck('role')->all());

        $staticItem = $template->items->first();
        $this->assertNotNull($staticItem->file_path);
        $this->assertNotSame($resource->file_path, $staticItem->file_path);
        $this->assertStringStartsWith(DocumentPackTemplateItem::Directory.'/', $staticItem->file_path);
        Storage::disk('local')->assertExists($staticItem->file_path);

        $resource->delete();
        Storage::disk('local')->assertMissing($resource->file_path);
        Storage::disk('local')->assertExists($staticItem->file_path);
    }

    public function test_applying_template_skips_quote_without_permission_and_creates_a_project_snapshot(): void
    {
        Storage::fake('local');

        $templateOwner = User::factory()->admin()->create();
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $template = DocumentPackTemplate::factory()->for($templateOwner, 'owner')->create([
            'name' => 'Visit Pack',
            'visibility' => ProjectVisibility::Open,
        ]);
        $templatePath = DocumentPackTemplateItem::Directory.'/'.$template->id.'/'.Str::uuid().'.pdf';
        $staticItem = DocumentPackTemplateItem::factory()->for($template, 'documentPackTemplate')->create([
            'role' => DocumentPackItemRole::CustomPdf,
            'source_type' => DocumentPackItemSource::Uploaded,
            'sort_order' => 0,
            'file_disk' => 'local',
            'file_path' => $templatePath,
            'original_filename' => 'visit-information.pdf',
            'configuration' => ['resource_display_name' => 'Visit Information'],
        ]);
        DocumentPackTemplateItem::factory()->for($template, 'documentPackTemplate')->create([
            'role' => DocumentPackItemRole::Quote,
            'source_type' => DocumentPackItemSource::Generated,
            'sort_order' => 1,
        ]);
        DocumentPackTemplateItem::factory()->for($template, 'documentPackTemplate')->create([
            'role' => DocumentPackItemRole::UnpricedSchedule,
            'source_type' => DocumentPackItemSource::Generated,
            'sort_order' => 2,
        ]);
        Storage::disk('local')->put($templatePath, $this->pdfWithText('Visit Information'));
        $this->actingAs($user);

        $this->assertFalse($user->can('output.produce-quote'));
        $this->get(route('projects.document-pack-templates.items.file', [
            'project' => $project,
            'documentPackTemplateItem' => $staticItem,
        ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $component = Livewire::test(OutputProject::class, ['record' => $project->id])
            ->set('outputTab', 'packs')
            ->set('selectedDocumentPackTemplateId', $template->id)
            ->call('useSelectedDocumentPackTemplate')
            ->assertNotified('Template applied with changes');

        $this->assertSame([
            DocumentPackItemRole::CustomPdf->value,
            DocumentPackItemRole::UnpricedSchedule->value,
        ], array_column(array_values($component->get('documentPackItems')), 'role'));
        $component
            ->assertSet('documentPackName', 'Visit Pack')
            ->assertSee('Visit Information')
            ->assertDontSee('Quote not approved')
            ->call('saveDocumentPack')
            ->assertHasNoErrors()
            ->assertNotified('Document pack saved');

        $pack = DocumentPack::query()->with('items')->where('project_id', $project->id)->sole();
        $packStaticItem = $pack->items->first();
        $this->assertSame([
            DocumentPackItemRole::CustomPdf,
            DocumentPackItemRole::UnpricedSchedule,
        ], $pack->items->pluck('role')->all());
        $this->assertNotSame($staticItem->file_path, $packStaticItem->file_path);
        Storage::disk('local')->assertExists($packStaticItem->file_path);

        $template->delete();
        Storage::disk('local')->assertMissing($templatePath);
        Storage::disk('local')->assertExists($packStaticItem->file_path);
    }

    public function test_template_visibility_matches_open_private_owner_and_team_rules(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $team = Team::query()->create(['name' => 'Projects', 'slug' => 'projects']);
        $otherTeam = Team::query()->create(['name' => 'Trade', 'slug' => 'trade']);
        $team->users()->attach([$owner->id, $member->id]);
        $otherTeam->users()->attach($outsider->id);
        $open = DocumentPackTemplate::factory()->for($outsider, 'owner')->create([
            'visibility' => ProjectVisibility::Open,
        ]);
        $ownersPrivate = DocumentPackTemplate::factory()->for($owner, 'owner')->create([
            'visibility' => ProjectVisibility::Private,
        ]);
        $otherPrivate = DocumentPackTemplate::factory()->for($outsider, 'owner')->create([
            'visibility' => ProjectVisibility::Private,
        ]);
        $teamTemplate = DocumentPackTemplate::factory()->for($owner, 'owner')->for($team)->create([
            'visibility' => ProjectVisibility::Team,
        ]);
        $otherTeamTemplate = DocumentPackTemplate::factory()->for($outsider, 'owner')->for($otherTeam)->create([
            'visibility' => ProjectVisibility::Team,
        ]);

        $ownerIds = DocumentPackTemplate::query()->visibleTo($owner)->pluck('id')->all();
        $memberIds = DocumentPackTemplate::query()->visibleTo($member)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([
            $open->id,
            $ownersPrivate->id,
            $teamTemplate->id,
        ], $ownerIds);
        $this->assertEqualsCanonicalizing([
            $open->id,
            $teamTemplate->id,
        ], $memberIds);
        $this->assertNotContains($otherPrivate->id, $memberIds);
        $this->assertNotContains($otherTeamTemplate->id, $memberIds);

        $project = Project::factory()->for($member)->create();
        $inaccessibleItem = DocumentPackTemplateItem::factory()
            ->for($otherPrivate, 'documentPackTemplate')
            ->create();
        $this->actingAs($member);

        $denied = false;

        try {
            Livewire::test(OutputProject::class, ['record' => $project->id])
                ->set('selectedDocumentPackTemplateId', $otherPrivate->id)
                ->call('useSelectedDocumentPackTemplate');
        } catch (ModelNotFoundException) {
            $denied = true;
        }

        $this->assertTrue($denied, 'An inaccessible template ID must be rejected server-side.');
        $this->get(route('projects.document-pack-templates.items.file', [
            'project' => $project,
            'documentPackTemplateItem' => $inaccessibleItem,
        ]))->assertForbidden();
    }

    public function test_invalid_template_team_selection_falls_back_to_private(): void
    {
        $user = User::factory()->create();
        $otherTeam = Team::query()->create(['name' => 'Other Team', 'slug' => 'other-team']);
        $project = Project::factory()->for($user)->create();
        $this->actingAs($user);

        $component = Livewire::test(OutputProject::class, ['record' => $project->id]);
        $itemKey = array_key_first($component->get('documentPackItems'));
        $component
            ->call('selectDocumentPackRole', $itemKey, DocumentPackItemRole::UnpricedSchedule->value)
            ->set('documentPackTemplateName', 'Private Fallback')
            ->set('documentPackTemplateVisibilityTarget', 'team:'.$otherTeam->id)
            ->call('saveDocumentPackAsTemplate')
            ->assertHasNoErrors()
            ->assertNotified('Document pack template saved');

        $template = DocumentPackTemplate::query()->sole();
        $this->assertSame(ProjectVisibility::Private, $template->visibility);
        $this->assertNull($template->team_id);
    }

    public function test_missing_static_template_pdf_is_omitted_without_losing_valid_placeholders(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $template = DocumentPackTemplate::factory()->for($owner, 'owner')->create([
            'visibility' => ProjectVisibility::Open,
        ]);
        DocumentPackTemplateItem::factory()->for($template, 'documentPackTemplate')->create([
            'role' => DocumentPackItemRole::CustomPdf,
            'source_type' => DocumentPackItemSource::Uploaded,
            'sort_order' => 0,
            'file_disk' => 'local',
            'file_path' => DocumentPackTemplateItem::Directory.'/'.$template->id.'/missing.pdf',
            'original_filename' => 'missing.pdf',
        ]);
        DocumentPackTemplateItem::factory()->for($template, 'documentPackTemplate')->create([
            'role' => DocumentPackItemRole::UnpricedSchedule,
            'source_type' => DocumentPackItemSource::Generated,
            'sort_order' => 1,
        ]);
        $this->actingAs($user);

        $component = Livewire::test(OutputProject::class, ['record' => $project->id])
            ->set('selectedDocumentPackTemplateId', $template->id)
            ->call('useSelectedDocumentPackTemplate')
            ->assertNotified('Template applied with changes');

        $this->assertSame(
            [DocumentPackItemRole::UnpricedSchedule->value],
            array_column(array_values($component->get('documentPackItems')), 'role'),
        );
    }

    public function test_document_pack_template_actions_require_document_pack_management(): void
    {
        $group = PermissionGroup::query()->create([
            'name' => 'Output Viewer',
            'slug' => 'template-output-viewer',
            'is_system' => false,
        ]);
        $group->permissions()->attach(Permission::query()->whereIn('key', [
            'projects.view',
            'output.view',
        ])->pluck('id'));
        $user = User::factory()->create(['permission_group_id' => $group->id]);
        $project = Project::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(OutputProject::class, ['record' => $project->id])
            ->call('openDocumentPackTemplatePicker')
            ->assertForbidden();
        Livewire::test(OutputProject::class, ['record' => $project->id])
            ->call('openSaveDocumentPackTemplate')
            ->assertForbidden();
        Livewire::test(OutputProject::class, ['record' => $project->id])
            ->set('selectedDocumentPackTemplateId', 999999)
            ->call('useSelectedDocumentPackTemplate')
            ->assertForbidden();
    }

    private function pdfWithText(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 12 Tf 72 720 Td ({$escaped}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
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
}
