<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Enums\ProjectRevisionStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\OutputProject;
use App\Filament\Resources\Projects\Pages\ProjectHistory;
use App\Filament\Resources\Projects\Pages\ValidationProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectRevision;
use App\Models\User;
use App\Services\ProjectSchedulePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

class AdminProjectResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_revisions_progress_from_p0_to_r1_and_r2(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();

        $this->assertSame(0, $project->revision);
        $this->assertSame('P0', $project->activeRevision->label());

        $component = Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertSee('P0')
            ->call('createNewRevision');

        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame('R1', $project->fresh()->activeRevision->label());

        $component->call('createNewRevision');

        $this->assertSame(2, $project->fresh()->revision);
        $this->assertSame('R2', $project->fresh()->activeRevision->label());
        $this->assertSame([0, 1, 2], $project->revisions()->pluck('revision_number')->all());
    }

    public function test_project_create_form_requires_key_project_fields(): void
    {
        $this->assertTrue(ProjectForm::createActionIsDisabled([
            'name' => 'Office Fit Out',
            'customer_name' => 'Example Customer',
            'reference_number' => '',
        ]));

        $this->assertFalse(ProjectForm::createActionIsDisabled([
            'name' => 'Office Fit Out',
            'customer_name' => 'Example Customer',
            'reference_number' => 'LQ-001',
        ]));

        $this->assertFalse(ProjectForm::createActionIsDisabled(
            [
                'name' => null,
                'customer_name' => null,
                'reference_number' => null,
            ],
            [
                'name' => 'Office Fit Out',
                'customer_name' => 'Example Customer',
                'reference_number' => 'LQ-001',
            ],
        ));
    }

    public function test_project_create_modal_submit_enables_when_required_fields_are_populated(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $component = Livewire::test(ListProjects::class)
            ->mountAction('create');

        $this->assertTrue(ProjectForm::createActionIsDisabled($component->instance()->mountedActions[0]['data'] ?? null));

        $component
            ->setActionData([
                'name' => 'Office Fit Out',
                'customer_name' => 'Example Customer',
                'reference_number' => 'LQ-001',
            ]);

        $this->assertFalse(ProjectForm::createActionIsDisabled($component->instance()->mountedActions[0]['data'] ?? null));
        $this->assertTrue($component->instance()->getMountedAction()->getModalSubmitAction()->isEnabled());
    }

    public function test_salesforce_project_names_are_normalised_to_title_case(): void
    {
        $this->assertSame(
            'Hartwest Primary School',
            ProjectForm::titleCaseProjectName('HARTWEST PRIMARY SCHOOL'),
        );
    }

    public function test_selected_salesforce_reference_label_only_contains_the_reference_number(): void
    {
        $this->assertSame(
            '22600',
            ProjectForm::salesforceSelectedReferenceLabel([
                'Project_Reference_Number__c' => '22600',
                'Name' => 'Hartwest Primary School',
            ]),
        );
    }

    public function test_selecting_a_salesforce_reference_selects_the_matching_project(): void
    {
        $this->travelTo('2026-06-23');

        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                ]);
            }

            if (str_contains($request->url(), '/services/data/v65.0/query/')) {
                return Http::response([
                    'records' => [[
                        'Id' => '006000000000001AAA',
                        'Name' => 'HARTEST PRIMARY SCHOOL',
                        'Project_Reference_Number__c' => '22600',
                        'Account' => ['Name' => 'Example Customer'],
                        'Owner' => ['Email' => 'owner@example.com'],
                    ]],
                ]);
            }

            return Http::response([], 500);
        });

        $component = Livewire::test(ListProjects::class)
            ->mountAction('create')
            ->setActionData([
                'customer_name' => 'Previous Customer',
                'site_location' => 'Previous Location',
                'owner_email' => 'previous-owner@example.com',
                'created_by_email' => 'wrong-creator@example.com',
                'date' => '2025-01-01',
                'branch_name' => 'Previous Branch',
                'cover_percentage' => '12.5',
                'value' => 1000,
                'quote_notes' => 'Previous quote notes',
                'internal_notes' => 'Previous internal notes',
                'general_notes' => 'Previous general notes',
            ])
            ->set('mountedActions.0.data.salesforce_reference_id', '006000000000001AAA');

        $data = $component->instance()->mountedActions[0]['data'];

        $this->assertSame('006000000000001AAA', $data['salesforce_id']);
        $this->assertSame('006000000000001AAA', $data['salesforce_reference_id']);
        $this->assertSame('Hartest Primary School', $data['name']);
        $this->assertSame('22600', $data['reference_number']);
        $this->assertStringContainsString('HARTEST PRIMARY SCHOOL', $data['salesforce_pending_data']);
        $this->assertNull($data['customer_name']);
        $this->assertNull($data['site_location']);
        $this->assertNull($data['owner_email']);
        $this->assertSame($admin->email, $data['created_by_email']);
        $this->assertSame('2026-06-23', $data['date']);
        $this->assertNull($data['branch_name']);
        $this->assertNull($data['cover_percentage']);
        $this->assertNull($data['value']);
        $this->assertNull($data['quote_notes']);
        $this->assertNull($data['internal_notes']);
        $this->assertNull($data['general_notes']);
    }

    public function test_duplicate_project_create_shows_a_validation_notification(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Project::factory()->for($admin)->create([
            'name' => 'Existing Salesforce Project',
            'reference_number' => '25948',
        ]);

        Livewire::test(ListProjects::class)
            ->mountAction('create')
            ->setActionData([
                'salesforce_project' => true,
                'salesforce_id' => '006000000000001AAA',
                'salesforce_reference_id' => '006000000000001AAA',
                'salesforce_pending_data' => json_encode([
                    'Id' => '006000000000001AAA',
                    'Name' => 'Existing Salesforce Project',
                    'Project_Reference_Number__c' => '25948',
                ]),
                'name' => 'Existing Salesforce Project',
                'reference_number' => '25948',
                'customer_name' => 'Example Customer',
                'created_by_email' => $admin->email,
                'date' => now()->toDateString(),
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['reference_number'])
            ->assertNotified('Project already exists');

        $this->assertSame(1, Project::query()->where('reference_number', '25948')->count());
    }

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
            ->assertSee('P0')
            ->assertSee('2 unresolved issues')
            ->assertSee('SKU "VALID-SKU" appears 2 times in this area.')
            ->assertSee('SKU "MISSING-SKU" was not found in the product catalogue.')
            ->assertDontSee('Area: Ground Floor')
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
            'status' => 'Pending',
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
            ->set('pastedProductData', "2\tTAB-SKU\t\"Discarded quoted\nmultiline description\"\t12.50\n3\tSECOND-SKU\tDiscarded description\t24.75\n1\tcustom-lower\tUnknown product\t5.00")
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', false);

        $lines = $area->lines()->orderBy('sort_order')->get();

        $this->assertCount(4, $lines);
        $this->assertSame($existingLine->id, $lines[0]->id);
        $this->assertSame('Unpriced', $lines[0]->status);

        $this->assertSame($tabProduct->id, $lines[1]->product_id);
        $this->assertSame('TAB-SKU', $lines[1]->code);
        $this->assertSame('Tab Product Visual Description', $lines[1]->description);
        $this->assertSame(2, $lines[1]->qty);
        $this->assertSame('12.50', $lines[1]->unit_price);
        $this->assertSame('Priced', $lines[1]->status);
        $this->assertSame(ProjectLineType::Standard, $lines[1]->type);

        $this->assertSame($commaProduct->id, $lines[2]->product_id);
        $this->assertSame('SECOND-SKU', $lines[2]->code);
        $this->assertSame('Second Product Visual Description', $lines[2]->description);
        $this->assertSame(3, $lines[2]->qty);
        $this->assertSame('24.75', $lines[2]->unit_price);
        $this->assertSame('Priced', $lines[2]->status);
        $this->assertSame(ProjectLineType::Standard, $lines[2]->type);

        $this->assertNull($lines[3]->product_id);
        $this->assertSame('CUSTOM-LOWER', $lines[3]->code);
        $this->assertSame(ProjectLineType::Custom, $lines[3]->type);
    }

    public function test_paste_products_updates_matching_lines_in_one_area(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();
        $otherArea = ProjectArea::create([
            'project_id' => $project->id,
            'project_revision_id' => $project->active_revision_id,
            'name' => 'Other Area',
            'sort_order' => 1,
        ]);
        $existingLine = $area->lines()->create([
            'code' => 'MATCH-SKU',
            'description' => 'Old description',
            'qty' => 5,
            'type' => ProjectLineType::Custom->value,
            'unit_price' => 1.00,
            'status' => 'Pending',
            'sort_order' => 0,
        ]);
        $otherAreaLine = $otherArea->lines()->create([
            'code' => 'MATCH-SKU',
            'description' => 'Other area description',
            'qty' => 7,
            'type' => ProjectLineType::Custom->value,
            'unit_price' => 2.00,
            'status' => 'Pending',
            'sort_order' => 0,
        ]);
        $product = Product::factory()->create([
            'sku' => 'MATCH-SKU',
            'product_name' => 'Matched Product',
            'description' => 'Matched Product Description',
            'price' => 99.99,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $area->id)
            ->set('pasteAcrossAllAreas', false)
            ->set('pastedProductData', "2\tMATCH-SKU\tDiscarded description\t12.50")
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', false);

        $this->assertSame(1, $area->lines()->count());

        $existingLine->refresh();
        $this->assertSame($product->id, $existingLine->product_id);
        $this->assertSame('Matched Product Description', $existingLine->description);
        $this->assertSame(2, $existingLine->qty);
        $this->assertSame('12.50', $existingLine->unit_price);
        $this->assertSame('Priced', $existingLine->status);
        $this->assertSame(ProjectLineType::Standard, $existingLine->type);

        $otherAreaLine->refresh();
        $this->assertSame('Other area description', $otherAreaLine->description);
        $this->assertSame(7, $otherAreaLine->qty);
        $this->assertSame('2.00', $otherAreaLine->unit_price);
        $this->assertSame('Pending', $otherAreaLine->status);
    }

    public function test_paste_products_across_all_areas_updates_without_changing_existing_quantities(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();
        $otherArea = ProjectArea::create([
            'project_id' => $project->id,
            'project_revision_id' => $project->active_revision_id,
            'name' => 'Other Area',
            'sort_order' => 1,
        ]);
        $firstLine = $area->lines()->create([
            'code' => 'SHARED-SKU',
            'description' => 'First old description',
            'qty' => 4,
            'type' => ProjectLineType::Custom->value,
            'unit_price' => 1.00,
            'status' => 'Pending',
            'sort_order' => 0,
        ]);
        $secondLine = $otherArea->lines()->create([
            'code' => 'SHARED-SKU',
            'description' => 'Second old description',
            'qty' => 9,
            'type' => ProjectLineType::Custom->value,
            'unit_price' => 2.00,
            'status' => 'Pending',
            'sort_order' => 0,
        ]);
        $missingFromPaste = $otherArea->lines()->create([
            'code' => 'OLD-SKU',
            'description' => 'Missing from paste',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'unit_price' => 3.00,
            'status' => 'Pending',
            'sort_order' => 1,
        ]);
        Product::factory()->create([
            'sku' => 'SHARED-SKU',
            'product_name' => 'Shared Product',
            'description' => 'Shared Product Description',
            'price' => 99.99,
        ]);
        $newProduct = Product::factory()->create([
            'sku' => 'NEW-SKU',
            'product_name' => 'New Product',
            'description' => 'New Product Description',
            'price' => 88.88,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $area->id)
            ->assertSet('pasteAcrossAllAreas', true)
            ->set('pastedProductData', "2\tSHARED-SKU\tDiscarded description\t12.50\n3\tNEW-SKU\tDiscarded description\t24.75")
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', false);

        $firstLine->refresh();
        $secondLine->refresh();
        $missingFromPaste->refresh();

        $this->assertSame(4, $firstLine->qty);
        $this->assertSame(9, $secondLine->qty);
        $this->assertSame('12.50', $firstLine->unit_price);
        $this->assertSame('12.50', $secondLine->unit_price);
        $this->assertSame('Priced', $firstLine->status);
        $this->assertSame('Priced', $secondLine->status);
        $this->assertSame('Unpriced', $missingFromPaste->status);

        $this->assertDatabaseHas('project_lines', [
            'project_area_id' => $area->id,
            'product_id' => $newProduct->id,
            'code' => 'NEW-SKU',
            'description' => 'New Product Description',
            'qty' => 3,
            'unit_price' => '24.75',
            'status' => 'Priced',
        ]);
    }

    public function test_project_line_status_shows_approved_when_line_is_approved(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();

        $area->lines()->create([
            'code' => 'APPROVED-SKU',
            'description' => 'Approved product',
            'qty' => 1,
            'type' => ProjectLineType::Standard->value,
            'unit_price' => 10.00,
            'status' => 'Priced',
            'approved' => true,
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'sort_order' => 0,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertSee('Approved')
            ->assertDontSee('Priced');
    }

    public function test_schedule_pdf_generation_is_recorded_in_activity_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'reference_number' => 'PDF-REF',
        ]);
        $revision = $project->activeRevision;

        $this->instance(ProjectSchedulePdfService::class, new class
        {
            public function filename(Project $project, ProjectRevision $revision): string
            {
                return 'schedule-PDF-REF-P0.pdf';
            }

            public function builder(Project $project, ProjectRevision $revision): object
            {
                return new class
                {
                    public function inline(string $filename): self
                    {
                        return $this;
                    }

                    public function toResponse($request)
                    {
                        return response('fake pdf');
                    }
                };
            }
        });

        $this->get(route('projects.pdf.schedule', [
            'project' => $project,
            'revision' => $revision->id,
        ]))->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'action_type' => 'schedule_pdf.generated',
            'revision_number' => 0,
        ]);

        Livewire::test(ProjectHistory::class, ['record' => $project->id])
            ->assertSee('Generated schedule PDF')
            ->assertSee('schedule-PDF-REF-P0.pdf');
    }

    public function test_project_page_displays_revision_totals(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Totals Project',
        ]);
        $area = $project->activeRevision->areas()->first();
        $area->lines()->createMany([
            [
                'code' => 'TOTAL-1',
                'description' => 'First total line',
                'qty' => 2,
                'type' => ProjectLineType::Standard->value,
                'unit_price' => 10.00,
                'sort_order' => 0,
            ],
            [
                'code' => 'TOTAL-2',
                'description' => 'Second total line',
                'qty' => 3,
                'type' => ProjectLineType::Standard->value,
                'unit_price' => 7.50,
                'sort_order' => 1,
            ],
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertSee('Total Qty')
            ->assertSee('5')
            ->assertSee('Line Items')
            ->assertSee('2')
            ->assertSee('Project Total')
            ->assertSee('42.50');
    }

    public function test_admin_can_view_output_options_for_the_active_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Output Project',
            'reference_number' => 'OUT-001',
        ]);

        Livewire::test(OutputProject::class, ['record' => $project->id])
            ->assertSee('Output Project')
            ->assertSee('Open')
            ->assertSee('P0')
            ->assertSee('Quote Approval')
            ->assertSee('Approval Not Requested')
            ->assertSee('Validation must pass before requesting approval')
            ->assertSee('Quote PDF requires')
            ->assertSee('Quote PDF')
            ->assertSee('Priced Schedule')
            ->assertSee('Unpriced Schedule')
            ->assertSee(route('projects.pdf.schedule', [
                'project' => $project,
                'revision' => $project->active_revision_id,
            ]), false)
            ->assertSee(route('projects.export.unpriced-csv', [
                'project' => $project,
                'revision' => $project->active_revision_id,
            ]), false);
    }

    public function test_admin_can_switch_between_single_pdf_and_document_pack_tabs(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();

        $component = Livewire::test(OutputProject::class, ['record' => $project->id])
            ->assertSet('outputTab', 'single')
            ->assertSee('Quote Approval')
            ->assertSee('Quick PDF/CSV Output')
            ->assertSee('Document Packs')
            ->assertSee('Priced quote with branding, cover percentage, totals and approval.')
            ->assertSee('Schedule export with pricing. Requires validation passed.')
            ->assertSee('Schedule without pricing. Always available.')
            ->assertDontSee('Build a reusable pack, drag documents into the required order');

        $this->assertLessThan(
            strpos($component->html(), 'role="tablist"'),
            strpos($component->html(), 'Quote Approval'),
        );

        $component
            ->set('outputTab', 'packs')
            ->assertSee('Quote Approval')
            ->assertSee('Build a reusable pack, drag documents into the required order')
            ->assertDontSee('Priced quote with branding, cover percentage, totals and approval.')
            ->assertDontSee('Schedule export with pricing. Requires validation passed.')
            ->assertDontSee('Schedule without pricing. Always available.');
    }

    public function test_admin_can_export_the_active_revision_as_csv_with_prices(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'reference_number' => 'CSV-001',
        ]);
        $project->activeRevision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
        ]);
        $area = $project->activeRevision->areas()->first();
        $area->lines()->create([
            'code' => 'CSV-SKU',
            'ref' => 'A1',
            'description' => 'CSV product',
            'qty' => 3,
            'type' => ProjectLineType::Standard->value,
            'unit_price' => 12.50,
            'notes' => 'CSV notes',
            'status' => 'Approved',
            'sort_order' => 0,
        ]);

        $response = $this->get(route('projects.export.csv', [
            'project' => $project,
            'revision' => $project->active_revision_id,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Area,Code,Ref,Description,Qty,Type,"Unit Price","Line Total",Notes,Status', $csv);
        $this->assertStringContainsString('CSV-SKU', $csv);
        $this->assertStringContainsString('12.50', $csv);
        $this->assertStringContainsString('37.50', $csv);
    }

    public function test_quote_pdf_can_be_generated_for_the_active_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'reference_number' => 'QUOTE-REF',
        ]);
        $revision = $project->activeRevision;
        $revision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $this->instance(ProjectSchedulePdfService::class, new class
        {
            public function quoteFilename(Project $project, ProjectRevision $revision): string
            {
                return 'quote-QUOTE-REF-P0.pdf';
            }

            public function quoteBuilder(Project $project, ProjectRevision $revision): object
            {
                return new class
                {
                    public function inline(string $filename): self
                    {
                        return $this;
                    }

                    public function toResponse($request)
                    {
                        return response('fake quote pdf');
                    }
                };
            }
        });

        $this->get(route('projects.pdf.quote', [
            'project' => $project,
            'revision' => $revision->id,
        ]))
            ->assertOk()
            ->assertSee('fake quote pdf');
    }

    public function test_priced_quote_template_displays_totals(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Quote User']);
        $project = Project::factory()->for($admin)->create([
            'name' => 'Quote Totals Project',
            'reference_number' => 'QT-001',
            'customer_name' => 'Example Customer',
            'contractor' => 'This Must Not Appear',
            'site_location' => 'Telford, Shropshire',
        ]);
        $revision = $project->activeRevision;
        $area = $revision->areas()->first();
        $area->lines()->createMany([
            [
                'code' => 'QUOTE-1',
                'description' => 'First quote line',
                'qty' => 4,
                'type' => ProjectLineType::Standard->value,
                'unit_price' => 11.25,
                'sort_order' => 0,
            ],
            [
                'code' => 'QUOTE-2',
                'description' => 'Second quote line',
                'qty' => 2,
                'type' => ProjectLineType::Standard->value,
                'unit_price' => 20.00,
                'sort_order' => 1,
            ],
        ]);

        $areas = ProjectArea::where('project_revision_id', $revision->id)
            ->with('lines')
            ->orderBy('sort_order')
            ->get();

        $html = view('pdfs.schedule', [
            'project' => $project->load('user'),
            'revision' => $revision,
            'areas' => $areas,
            'documentTitle' => 'Lighting Quote',
            'showPrices' => true,
        ])->render();

        $this->assertStringContainsString('Quote total', $html);
        $this->assertStringContainsString('&pound;85.00', $html);
        $this->assertStringContainsString('Total quantity', $html);
        $this->assertStringContainsString('Line items', $html);
    }

    public function test_p0_schedule_and_quote_hide_revision_and_contractor_metadata(): void
    {
        $user = User::factory()->create(['name' => 'PDF User']);
        $project = Project::factory()->for($user)->create([
            'contractor' => 'Hidden Contractor',
            'site_location' => 'Visible Project Location',
        ]);
        $revision = $project->activeRevision;
        $areas = $revision->areas()->with('lines')->get();

        foreach ([
            ['title' => 'Lighting Schedule', 'showPrices' => false],
            ['title' => 'Lighting Quote', 'showPrices' => true],
        ] as $document) {
            $html = view('pdfs.schedule', [
                'project' => $project->load('user'),
                'revision' => $revision,
                'areas' => $areas,
                'documentTitle' => $document['title'],
                'showPrices' => $document['showPrices'],
            ])->render();

            $this->assertStringNotContainsString('Rev:', $html);
            $this->assertStringNotContainsString('Revision:', $html);
            $this->assertStringNotContainsString('Contractor:', $html);
            $this->assertStringNotContainsString('Hidden Contractor', $html);
            $this->assertStringContainsString('Project Location:', $html);
            $this->assertStringContainsString('Visible Project Location', $html);
        }
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
        $fiveColumnProduct = Product::factory()->create([
            'sku' => 'FIVE-COL',
            'product_name' => 'Five Column Product',
            'description' => 'Five Column Product Description',
            'price' => 10.00,
        ]);
        $sixColumnProduct = Product::factory()->create([
            'sku' => 'SIX-COL',
            'product_name' => 'Six Column Product',
            'description' => 'Six Column Product Description',
            'price' => 20.00,
        ]);
        $sevenColumnProduct = Product::factory()->create([
            'sku' => 'SEVEN-COL',
            'product_name' => 'Seven Column Product',
            'description' => 'Seven Column Product Description',
            'price' => 30.00,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $area->id)
            ->set('pastedProductData', implode("\n", [
                "1\tTWO-COL",
                "2\tTHREE-COL\tDiscarded description",
                "3\tFOUR-COL\tDiscarded description\t44.44",
                "4\tFIVE-COL\tDiscarded description\t55.55\t10%",
                "5\tSIX-COL\tDiscarded description\t66.66\t20%\t11.11",
                "6\tSEVEN-COL\tDiscarded description\t77.77\t30%\t22.22\t133.32",
            ]))
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', false)
            ->assertSet('pasteProductsError', null);

        $lines = $area->lines()->orderBy('sort_order')->get();

        $this->assertCount(6, $lines);

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

        $this->assertSame($fiveColumnProduct->id, $lines[3]->product_id);
        $this->assertSame('FIVE-COL', $lines[3]->code);
        $this->assertSame('Five Column Product Description', $lines[3]->description);
        $this->assertSame(4, $lines[3]->qty);
        $this->assertSame('55.55', $lines[3]->unit_price);

        $this->assertSame($sixColumnProduct->id, $lines[4]->product_id);
        $this->assertSame('SIX-COL', $lines[4]->code);
        $this->assertSame('Six Column Product Description', $lines[4]->description);
        $this->assertSame(5, $lines[4]->qty);
        $this->assertSame('11.11', $lines[4]->unit_price);

        $this->assertSame($sevenColumnProduct->id, $lines[5]->product_id);
        $this->assertSame('SEVEN-COL', $lines[5]->code);
        $this->assertSame('Seven Column Product Description', $lines[5]->description);
        $this->assertSame(6, $lines[5]->qty);
        $this->assertSame('22.22', $lines[5]->unit_price);
    }

    public function test_paste_products_rejects_single_column_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $area = $project->activeRevision->areas()->first();

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $area->id)
            ->set('pastedProductData', 'ONLY-QTY')
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', true)
            ->assertSet('pasteProductsError', '1 pasted row could not be imported. Check that each row has Qty and SKU columns.')
            ->assertSee('1 pasted row could not be imported.');

        $this->assertSame(0, $area->lines()->count());
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

    public function test_free_typed_line_code_is_stored_uppercase(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $line = $project->activeRevision->areas()->first()->lines()->create([
            'description' => 'Manual line',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('updateLineField', $line->id, 'code', 'abc123x');

        $this->assertSame('ABC123X', $line->fresh()->code);
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
        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action_type' => 'project.details_saved',
        ]);
    }

    public function test_salesforce_project_details_save_logs_uploaded_pdf_url(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Salesforce Project',
            'customer_name' => 'Example Customer',
            'reference_number' => '22600',
            'salesforce_project' => true,
            'salesforce_id' => '006000000000001AAA',
        ]);
        $project->activeRevision->areas()->first()->lines()->create([
            'code' => 'PDF-SKU',
            'description' => 'PDF line',
            'qty' => 1,
            'type' => ProjectLineType::Standard->value,
            'sort_order' => 0,
        ]);

        $this->instance(ProjectSchedulePdfService::class, new class
        {
            public function filename(Project $project, ProjectRevision $revision): string
            {
                return 'schedule-22600-P0.pdf';
            }

            public function content(Project $project, ProjectRevision $revision): string
            {
                return '%PDF-1.4 test content';
            }
        });

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

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->callAction('editProject', [
                'name' => 'Salesforce Project Updated',
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

        $log = ActivityLog::where('project_id', $project->id)
            ->where('action_type', 'project.details_saved')
            ->latest()
            ->firstOrFail();

        $this->assertSame(
            'https://example.my.salesforce.com/lightning/r/ContentDocument/069000000000001AAA/view',
            $log->payload['salesforce_pdf_url'] ?? null,
        );
        $this->assertSame('schedule-22600-P0.pdf', $log->payload['salesforce_pdf_filename'] ?? null);

        Livewire::test(ProjectHistory::class, ['record' => $project->id])
            ->assertSee('Saved project details and uploaded', false)
            ->assertSee('View file')
            ->assertSee('https://example.my.salesforce.com/lightning/r/ContentDocument/069000000000001AAA/view', false);
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

        $this->assertSame(1, $revisionLog?->revision_number);
    }

    public function test_activity_logs_table_shows_the_revision_number(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'reference_number' => 'REF-003',
            'revision' => 3,
        ]);

        ActivityLog::create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'action_type' => 'project.updated',
            'user_email_snapshot' => $admin->email,
            'project_name_snapshot' => $project->name,
            'payload' => null,
        ]);

        Livewire::test(ListActivityLogs::class)
            ->assertSee('REF-003')
            ->assertSee('R3');
    }

    public function test_project_history_only_shows_activity_for_the_current_project(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Visible Project',
            'reference_number' => 'VISIBLE-REF',
            'visibility' => ProjectVisibility::Private,
            'revision' => 3,
        ]);
        $otherProject = Project::factory()->for($admin)->create([
            'name' => 'Hidden Project',
            'reference_number' => 'HIDDEN-REF',
            'revision' => 9,
        ]);

        ActivityLog::create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'action_type' => 'project.updated',
            'user_email_snapshot' => $admin->email,
            'project_name_snapshot' => $project->name,
            'payload' => [
                'customer_name' => [
                    'old' => 'Old Customer',
                    'new' => 'New Customer',
                ],
            ],
        ]);

        ActivityLog::create([
            'user_id' => $admin->id,
            'project_id' => $otherProject->id,
            'action_type' => 'project.updated',
            'user_email_snapshot' => $admin->email,
            'project_name_snapshot' => $otherProject->name,
            'payload' => [
                'customer_name' => [
                    'old' => 'Other Old Customer',
                    'new' => 'Other New Customer',
                ],
            ],
        ]);

        Livewire::test(ProjectHistory::class, ['record' => $project->id])
            ->assertSee('Visible Project')
            ->assertSee('Private')
            ->assertSee('VISIBLE-REF')
            ->assertSee('R3')
            ->assertSee('New Customer')
            ->assertDontSee('Hidden Project')
            ->assertDontSee('HIDDEN-REF')
            ->assertDontSee('R9')
            ->assertDontSee('Other New Customer');

        Livewire::test(ListActivityLogs::class)
            ->assertSee('VISIBLE-REF')
            ->assertSee('HIDDEN-REF');
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
