<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

class AdminProjectResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_fields_can_only_be_updated_in_the_viewed_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $activeArea = $project->activeRevision->areas()->first();

        $activeRevisionLine = $activeArea->lines()->create([
            'code' => 'ACTIVE',
            'description' => 'Active revision line',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        $secondRevision = ProjectRevision::create([
            'project_id' => $project->id,
            'revision_number' => 2,
            'created_by' => $admin->id,
        ]);

        ProjectArea::create([
            'project_id' => $project->id,
            'project_revision_id' => $secondRevision->id,
            'name' => 'Second revision area',
            'sort_order' => 0,
        ]);

        $this->assertLivewireCallFails(function () use ($project, $secondRevision, $activeRevisionLine): void {
            Livewire::test(ViewProject::class, ['record' => $project->id])
                ->set('viewingRevisionId', $secondRevision->id)
                ->call('updateLineField', $activeRevisionLine->id, 'description', 'Cross-revision edit');
        });

        $this->assertSame('Active revision line', $activeRevisionLine->fresh()->description);
    }

    public function test_lines_cannot_be_sorted_into_an_area_from_another_project(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $otherProject = Project::factory()->for($admin)->create();

        $line = $project->activeRevision->areas()->first()->lines()->create([
            'code' => 'SOURCE',
            'description' => 'Source line',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        $otherProjectArea = $otherProject->activeRevision->areas()->first();

        $this->assertLivewireCallFails(function () use ($project, $line, $otherProjectArea): void {
            Livewire::test(ViewProject::class, ['record' => $project->id])
                ->call('sortLine', $line->id, 0, $otherProjectArea->id);
        });

        $this->assertSame($line->project_area_id, $line->fresh()->project_area_id);
    }

    public function test_lines_can_only_be_duplicated_in_the_viewed_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $activeArea = $project->activeRevision->areas()->first();

        $activeRevisionLine = $activeArea->lines()->create([
            'code' => 'DUP',
            'description' => 'Do not duplicate',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        $secondRevision = ProjectRevision::create([
            'project_id' => $project->id,
            'revision_number' => 2,
            'created_by' => $admin->id,
        ]);

        ProjectArea::create([
            'project_id' => $project->id,
            'project_revision_id' => $secondRevision->id,
            'name' => 'Second revision area',
            'sort_order' => 0,
        ]);

        $this->assertLivewireCallFails(function () use ($project, $secondRevision, $activeRevisionLine): void {
            Livewire::test(ViewProject::class, ['record' => $project->id])
                ->set('viewingRevisionId', $secondRevision->id)
                ->call('duplicateLine', $activeRevisionLine->id);
        });

        $this->assertSame(1, $activeArea->lines()->count());
    }

    public function test_lines_can_only_be_deleted_in_the_viewed_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $activeArea = $project->activeRevision->areas()->first();

        $activeRevisionLine = $activeArea->lines()->create([
            'code' => 'KEEP',
            'description' => 'Keep me',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        $secondRevision = ProjectRevision::create([
            'project_id' => $project->id,
            'revision_number' => 2,
            'created_by' => $admin->id,
        ]);

        ProjectArea::create([
            'project_id' => $project->id,
            'project_revision_id' => $secondRevision->id,
            'name' => 'Second revision area',
            'sort_order' => 0,
        ]);

        $this->assertLivewireCallFails(function () use ($project, $secondRevision, $activeRevisionLine): void {
            Livewire::test(ViewProject::class, ['record' => $project->id])
                ->set('viewingRevisionId', $secondRevision->id)
                ->call('deleteLine', $activeRevisionLine->id);
        });

        $this->assertDatabaseHas('project_lines', ['id' => $activeRevisionLine->id]);
    }

    public function test_lines_can_be_sorted_between_areas_in_the_viewed_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $sourceArea = $project->activeRevision->areas()->first();
        $targetArea = ProjectArea::create([
            'project_id' => $project->id,
            'project_revision_id' => $project->active_revision_id,
            'name' => 'First Floor',
            'sort_order' => 1,
        ]);

        $line = $sourceArea->lines()->create([
            'code' => 'MOVE',
            'description' => 'Move me',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('sortLine', $line->id, 0, $targetArea->id);

        $this->assertSame($targetArea->id, $line->fresh()->project_area_id);
    }

    /**
     * @param  callable(): void  $callback
     */
    private function assertLivewireCallFails(callable $callback): void
    {
        $failed = false;

        try {
            $callback();
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'The Livewire call should fail when scoped records do not match.');
    }
}
