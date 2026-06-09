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
use Illuminate\Support\Facades\Http;
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
            'description' => 'Priced Product Visual Description',
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
            'description' => 'Priced Product Visual Description',
            'qty' => 2,
            'unit_price' => '24.50',
        ]);
    }

    public function test_admin_can_paste_products_into_an_area(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();
        $existingLine = $area->lines()->create([
            'code' => 'EXISTING',
            'description' => 'Existing line',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);
        $tabProduct = Product::factory()->create([
            'sku' => 'TAB-SKU',
            'product_name' => 'Tab Product',
            'description' => 'Tab Product Visual Description',
            'price' => 99.99,
        ]);
        $commaProduct = Product::factory()->create([
            'sku' => 'SECOND-SKU',
            'product_name' => 'Second Product',
            'description' => 'Second Product Visual Description',
            'price' => 88.88,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $area->id)
            ->assertSet('pasteProductsModalOpen', true)
            ->set('pastedProductData', "2\tTAB-SKU\t\"Discarded quoted\nmultiline description\"\t12.50\n3\tSECOND-SKU\tDiscarded description\t24.75")
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', false);

        $lines = $area->lines()->orderBy('sort_order')->get();

        $this->assertCount(3, $lines);
        $this->assertSame($existingLine->id, $lines[0]->id);

        $this->assertSame($tabProduct->id, $lines[1]->product_id);
        $this->assertSame('TAB-SKU', $lines[1]->code);
        $this->assertSame('Tab Product Visual Description', $lines[1]->description);
        $this->assertSame(2, $lines[1]->qty);
        $this->assertSame('12.50', $lines[1]->unit_price);
        $this->assertSame(ProjectLineType::Standard, $lines[1]->type);

        $this->assertSame($commaProduct->id, $lines[2]->product_id);
        $this->assertSame('SECOND-SKU', $lines[2]->code);
        $this->assertSame('Second Product Visual Description', $lines[2]->description);
        $this->assertSame(3, $lines[2]->qty);
        $this->assertSame('24.75', $lines[2]->unit_price);
        $this->assertSame(ProjectLineType::Standard, $lines[2]->type);
    }

    public function test_admin_can_paste_products_with_optional_description_and_price_columns(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();
        $twoColumnProduct = Product::factory()->create([
            'sku' => 'TWO-COL',
            'product_name' => 'Two Column Product',
            'description' => 'Two Column Product Description',
            'price' => 12.34,
        ]);
        $threeColumnProduct = Product::factory()->create([
            'sku' => 'THREE-COL',
            'product_name' => 'Three Column Product',
            'description' => 'Three Column Product Description',
            'price' => 56.78,
        ]);
        $fourColumnProduct = Product::factory()->create([
            'sku' => 'FOUR-COL',
            'product_name' => 'Four Column Product',
            'description' => 'Four Column Product Description',
            'price' => 90.12,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $area->id)
            ->set('pastedProductData', "1\tTWO-COL\n2\tTHREE-COL\tDiscarded description\n3\tFOUR-COL\tDiscarded description\t44.44")
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', false)
            ->assertSet('pasteProductsError', null);

        $lines = $area->lines()->orderBy('sort_order')->get();

        $this->assertCount(3, $lines);

        $this->assertSame($twoColumnProduct->id, $lines[0]->product_id);
        $this->assertSame('TWO-COL', $lines[0]->code);
        $this->assertSame('Two Column Product Description', $lines[0]->description);
        $this->assertSame(1, $lines[0]->qty);
        $this->assertSame('12.34', $lines[0]->unit_price);

        $this->assertSame($threeColumnProduct->id, $lines[1]->product_id);
        $this->assertSame('THREE-COL', $lines[1]->code);
        $this->assertSame('Three Column Product Description', $lines[1]->description);
        $this->assertSame(2, $lines[1]->qty);
        $this->assertSame('56.78', $lines[1]->unit_price);

        $this->assertSame($fourColumnProduct->id, $lines[2]->product_id);
        $this->assertSame('FOUR-COL', $lines[2]->code);
        $this->assertSame('Four Column Product Description', $lines[2]->description);
        $this->assertSame(3, $lines[2]->qty);
        $this->assertSame('44.44', $lines[2]->unit_price);
    }

    public function test_paste_products_warns_when_no_rows_can_be_imported(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $area->id)
            ->set('pastedProductData', "not-a-qty\t\tDescription\tfree\n\tNO-QTY\tDescription\t12.00")
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', true)
            ->assertSet('pasteProductsError', '2 pasted rows could not be imported. Check that each row has Qty and SKU columns.')
            ->assertSee('2 pasted rows could not be imported.');

        $this->assertSame(0, $area->lines()->count());
    }

    public function test_paste_products_warns_when_some_rows_are_skipped(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();
        $product = Product::factory()->create([
            'sku' => 'VALID-SKU',
            'product_name' => 'Valid Product',
            'description' => 'Valid Product Description',
            'price' => 99.99,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $area->id)
            ->assertSet('pasteProductsError', null)
            ->set('pastedProductData', "bad\tBROKEN\tDescription\t12.00\n2\tVALID-SKU\tDescription\t14.50\n3\tNO-PRICE\tDescription\tfree")
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', false)
            ->assertSet('pasteProductsError', null)
            ->assertNotified('Some products were not added');

        $line = $area->lines()->first();

        $this->assertSame($product->id, $line->product_id);
        $this->assertSame('VALID-SKU', $line->code);
        $this->assertSame(2, $line->qty);
        $this->assertSame('14.50', $line->unit_price);
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

    public function test_line_fields_can_be_cleared_for_spacing_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $line = $project->activeRevision->areas()->first()->lines()->create([
            'code' => 'SPACER',
            'ref' => 'A1',
            'description' => 'Spacer row',
            'qty' => 1,
            'unit_price' => 12.34,
            'notes' => 'Spacing note',
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        $component = Livewire::test(ViewProject::class, ['record' => $project->id]);

        foreach (['code', 'ref', 'description', 'qty', 'unit_price', 'notes'] as $field) {
            $component->call('updateLineField', $line->id, $field, '');
        }

        $line->refresh();

        $this->assertNull($line->code);
        $this->assertNull($line->ref);
        $this->assertNull($line->description);
        $this->assertNull($line->qty);
        $this->assertNull($line->unit_price);
        $this->assertNull($line->notes);
    }

    public function test_salesforce_project_details_save_does_not_upload_pdf_without_products(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Empty Salesforce Project',
            'customer_name' => 'Example Customer',
            'reference_number' => '22600',
            'salesforce_project' => true,
            'salesforce_id' => '006000000000001AAA',
        ]);

        Http::fake();

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->callAction('editProject', [
                'name' => 'Empty Salesforce Project Updated',
                'reference_number' => '22600',
                'customer_name' => 'Example Customer',
                'contractor' => null,
                'site_location' => null,
                'owner_email' => $project->owner_email,
                'created_by_email' => $project->created_by_email,
                'department' => null,
                'date' => $project->date->format('Y-m-d'),
                'revision' => $project->revision,
                'visibility' => $project->visibility->value,
                'branch_name' => null,
                'cover_percentage' => null,
                'quote_notes' => null,
                'internal_notes' => null,
                'general_notes' => null,
            ]);

        Http::assertNothingSent();
        $this->assertSame('Empty Salesforce Project Updated', $project->fresh()->name);
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
