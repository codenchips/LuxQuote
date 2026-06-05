<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Projects\Pages\ValidationProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\ActivityLog;
use App\Models\Product;
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

    public function test_admin_can_validate_the_active_project_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Hospital Lighting',
            'customer_name' => 'Example Customer',
        ]);
        $groundFloor = $project->activeRevision->areas()->first();
        $firstFloor = ProjectArea::create([
            'project_id' => $project->id,
            'project_revision_id' => $project->active_revision_id,
            'name' => 'First Floor',
            'sort_order' => 1,
        ]);

        Product::factory()->create(['sku' => 'VALID-SKU', 'price' => null]);

        $groundFloor->lines()->createMany([
            [
                'code' => 'VALID-SKU',
                'description' => 'First valid product',
                'qty' => 1,
                'type' => ProjectLineType::Standard->value,
                'sort_order' => 0,
            ],
            [
                'code' => 'valid-sku',
                'description' => 'Duplicate valid product',
                'qty' => 1,
                'type' => ProjectLineType::Standard->value,
                'sort_order' => 1,
            ],
            [
                'code' => 'MISSING-SKU',
                'description' => 'Missing product',
                'qty' => 1,
                'type' => ProjectLineType::Custom->value,
                'sort_order' => 2,
            ],
        ]);

        $firstFloor->lines()->create([
            'code' => 'VALID-SKU',
            'description' => 'Valid in another area',
            'qty' => 1,
            'type' => ProjectLineType::Standard->value,
            'sort_order' => 0,
        ]);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->assertSee('Hospital Lighting')
            ->assertSee('Example Customer')
            ->assertSee('Rev 1')
            ->assertSee('2 unresolved issues')
            ->assertSee('SKU "VALID-SKU" appears 2 times in this area.')
            ->assertSee('SKU "MISSING-SKU" was not found in the product catalogue.')
            ->assertSee('Area: Ground Floor')
            ->assertDontSee('Area: First Floor');
    }

    public function test_run_validation_rechecks_project_lines(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();

        $component = Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->assertSee('No unresolved issues');

        $area->lines()->create([
            'code' => 'NEW-MISSING-SKU',
            'description' => 'New missing product',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        $component
            ->call('runValidation')
            ->assertSee('1 unresolved issue')
            ->assertSee('SKU "NEW-MISSING-SKU" was not found in the product catalogue.');
    }

    public function test_validation_ignores_lines_from_historical_revisions(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $historicalRevision = ProjectRevision::create([
            'project_id' => $project->id,
            'revision_number' => 2,
            'created_by' => $admin->id,
        ]);
        $historicalArea = ProjectArea::create([
            'project_id' => $project->id,
            'project_revision_id' => $historicalRevision->id,
            'name' => 'Historical Area',
            'sort_order' => 0,
        ]);

        $historicalArea->lines()->create([
            'code' => 'HISTORICAL-MISSING-SKU',
            'description' => 'Historical missing product',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->assertSee('No unresolved issues')
            ->assertDontSee('HISTORICAL-MISSING-SKU');
    }

    public function test_adding_products_populates_unit_price_from_product_price(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();
        $product = Product::factory()->create([
            'sku' => 'PRICED-SKU',
            'product_name' => 'Priced Product',
            'price' => 24.50,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->set('productPickerAreaId', $area->id)
            ->set('productSelections', [$product->id => ['qty' => 2]])
            ->call('addSelectedProducts');

        $this->assertDatabaseHas('project_lines', [
            'project_area_id' => $area->id,
            'product_id' => $product->id,
            'code' => 'PRICED-SKU',
            'qty' => 2,
            'unit_price' => '24.50',
        ]);
    }

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

    public function test_activity_logs_snapshot_the_project_revision_number(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('createNewRevision');

        $revisionLog = ActivityLog::where('project_id', $project->id)
            ->where('action_type', 'revision.created')
            ->first();

        $this->assertSame(2, $revisionLog?->revision_number);
    }

    public function test_activity_logs_table_shows_the_revision_number(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create(['revision' => 3]);

        ActivityLog::create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'action_type' => 'project.updated',
            'user_email_snapshot' => $admin->email,
            'project_name_snapshot' => $project->name,
            'payload' => null,
        ]);

        Livewire::test(ListActivityLogs::class)
            ->assertSee('R3');
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
