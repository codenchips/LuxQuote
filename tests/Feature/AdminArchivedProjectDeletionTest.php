<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\ActivityLog;
use App\Models\DocumentPack;
use App\Models\PdfGeneration;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectLock;
use App\Models\ProjectPresence;
use App\Models\ReportingEvent;
use App\Models\User;
use App\Services\ArchivedProjectDeletionService;
use App\Services\PdfDownloadUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminArchivedProjectDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_rows_replace_the_edit_action_with_delete_for_admins_and_managers(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->manager()->create()] as $actor) {
            $this->actingAs($actor);
            $archived = Project::factory()->for($actor)->create(['status' => ProjectStatus::Archived]);
            $active = Project::factory()->for($actor)->create(['status' => ProjectStatus::InProgress]);

            Livewire::test(ListProjects::class)
                ->filterTable('status', [ProjectStatus::Archived])
                ->assertTableActionHidden('editProject', $archived)
                ->assertTableActionVisible('deleteArchived', $archived)
                ->assertTableActionVisible('restore', $archived);

            Livewire::test(ListProjects::class)
                ->resetTableFilters()
                ->assertTableActionVisible('editProject', $active)
                ->assertTableActionHidden('deleteArchived', $active);
        }
    }

    public function test_manager_can_delete_archived_project_data_and_files_without_affecting_other_projects(): void
    {
        Storage::fake('local');
        $actor = User::factory()->manager()->create();
        $this->actingAs($actor);
        $project = Project::factory()->for($actor)->create(['status' => ProjectStatus::Archived]);
        $otherProject = Project::factory()->for($actor)->create();
        $area = $project->activeRevision->areas()->first();
        $product = Product::factory()->create();
        $line = $area->lines()->create(['code' => 'DELETE-ME', 'qty' => 2, 'product_id' => $product->id]);
        $revisionId = $project->active_revision_id;
        $anotherRevision = $project->revisions()->create(['revision_number' => 2, 'created_by' => $actor->id]);
        $anotherArea = $anotherRevision->areas()->create(['project_id' => $project->id, 'name' => 'Earlier revision area']);
        $anotherLine = $anotherArea->lines()->create(['code' => 'ANOTHER-LINE', 'qty' => 1]);
        $tender = $project->tenders()->create(['salesforce_account_id' => '001-delete-test', 'account_name' => 'Test tender']);
        $lock = ProjectLock::create(['project_id' => $project->id, 'user_id' => $actor->id, 'locked_at' => now(), 'last_activity_at' => now()]);
        $presence = ProjectPresence::create(['project_id' => $project->id, 'user_id' => $actor->id, 'last_seen_at' => now()]);
        $pack = DocumentPack::factory()->for($project)->create();
        Storage::disk('local')->put('document-packs/delete-me.pdf', 'uploaded pdf');
        Storage::disk('local')->put('document-packs/keep-me.pdf', 'other project pdf');
        $pack->items()->create([
            'role' => 'cover',
            'source_type' => 'uploaded',
            'sort_order' => 0,
            'file_disk' => 'local',
            'file_path' => 'document-packs/delete-me.pdf',
        ]);
        $report = ReportingEvent::factory()->create(['project_id' => $project->id]);
        $historyIds = ActivityLog::query()->where('project_id', $project->id)->pluck('id');
        $sourcePath = tempnam(sys_get_temp_dir(), 'archived-project-');
        File::put($sourcePath, 'prepared pdf');
        $downloads = app(PdfDownloadUrlService::class);
        $download = $downloads->register(['path' => $sourcePath, 'filename' => 'project.pdf'], $actor->id);
        $preparedPath = $downloads->metadata($download['token'], $actor->id)['path'];
        $generation = PdfGeneration::factory()->for($project)->for($actor)->create([
            'status' => PdfGeneration::StatusCompleted,
            'result' => $download,
        ]);

        Livewire::test(ListProjects::class)
            ->filterTable('status', [ProjectStatus::Archived])
            ->callTableAction('deleteArchived', $project);

        foreach ([
            ['projects', $project->id],
            ['project_revisions', $revisionId],
            ['project_areas', $area->id],
            ['project_lines', $line->id],
            ['project_revisions', $anotherRevision->id],
            ['project_areas', $anotherArea->id],
            ['project_lines', $anotherLine->id],
            ['project_tenders', $tender->id],
            ['project_locks', $lock->id],
            ['project_presences', $presence->id],
            ['document_packs', $pack->id],
            ['pdf_generations', $generation->id],
            ['reporting_events', $report->id],
        ] as [$table, $id]) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }

        foreach ($historyIds as $id) {
            $this->assertDatabaseMissing('activity_logs', ['id' => $id]);
        }

        $this->assertDatabaseHas('projects', ['id' => $otherProject->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('activity_logs', ['action_type' => 'project.deleted', 'project_name_snapshot' => $project->name]);
        Storage::disk('local')->assertMissing('document-packs/delete-me.pdf');
        Storage::disk('local')->assertExists('document-packs/keep-me.pdf');
        $this->assertFileDoesNotExist($preparedPath);
        $this->assertNull($downloads->metadata($download['token'], $actor->id));
    }

    public function test_users_without_the_new_permission_cannot_call_the_archived_delete_action(): void
    {
        $actor = User::factory()->technical()->create();
        $this->actingAs($actor);
        $project = Project::factory()->for($actor)->create(['status' => ProjectStatus::Archived]);
        $component = Livewire::test(ListProjects::class)
            ->filterTable('status', [ProjectStatus::Archived])
            ->assertTableActionHidden('deleteArchived', $project);
        $action = $component->instance()->getTable()->getAction('deleteArchived');
        $action->record($project);

        try {
            $action->call();
            $this->fail('An unauthorized delete must fail server-side.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_active_project_deletion_permission_does_not_grant_archived_deletion(): void
    {
        $actor = User::factory()->technical()->create();
        $actor->permissionGroup->permissions()->syncWithoutDetaching([
            Permission::where('key', 'projects.delete-permanently')->value('id'),
        ]);
        $project = Project::factory()->for($actor)->create(['status' => ProjectStatus::Archived]);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        app(ArchivedProjectDeletionService::class)->delete($project, $actor);
    }

    public function test_archived_delete_checks_the_current_status_after_a_project_is_restored(): void
    {
        $actor = User::factory()->manager()->create();
        $project = Project::factory()->for($actor)->create(['status' => ProjectStatus::Archived]);
        $staleProject = $project->fresh();
        $project->update(['status' => ProjectStatus::InProgress]);

        try {
            app(ArchivedProjectDeletionService::class)->delete($staleProject, $actor);
            $this->fail('Restored projects must not be deleted by a stale archived action.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_manager_cannot_delete_another_users_private_archived_project(): void
    {
        $actor = User::factory()->manager()->create();
        $project = Project::factory()->create(['status' => ProjectStatus::Archived, 'visibility' => ProjectVisibility::Private]);

        try {
            app(ArchivedProjectDeletionService::class)->delete($project, $actor);
            $this->fail('Project visibility must also be checked server-side.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_the_permission_can_be_removed_from_the_manager_group(): void
    {
        $actor = User::factory()->manager()->create();
        $actor->permissionGroup->permissions()->detach(Permission::where('key', 'projects.delete-archived')->value('id'));
        $actor->unsetRelation('permissionGroup');
        $project = Project::factory()->for($actor)->create(['status' => ProjectStatus::Archived]);
        $this->actingAs($actor);

        Livewire::test(ListProjects::class)
            ->filterTable('status', [ProjectStatus::Archived])
            ->assertTableActionHidden('deleteArchived', $project);
        $this->assertFalse($actor->can('projects.delete-archived'));
    }

    public function test_the_new_permission_can_be_granted_without_project_editing_or_active_deletion(): void
    {
        $actor = User::factory()->technical()->create();
        $actor->permissionGroup->permissions()->syncWithoutDetaching([
            Permission::where('key', 'projects.delete-archived')->value('id'),
        ]);
        $this->actingAs($actor);
        $project = Project::factory()->for($actor)->create(['status' => ProjectStatus::Archived]);
        $this->assertFalse($actor->can('projects.update-details'));
        $this->assertFalse($actor->can('projects.delete-permanently'));

        $component = Livewire::test(ListProjects::class)
            ->filterTable('status', [ProjectStatus::Archived])
            ->assertTableActionVisible('deleteArchived', $project)
            ->mountTableAction('deleteArchived', $project);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $component->callMountedTableAction();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }
}
