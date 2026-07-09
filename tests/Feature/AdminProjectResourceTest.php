<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Enums\ProjectRevisionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\OutputProject;
use App\Filament\Resources\Projects\Pages\ProjectHistory;
use App\Filament\Resources\Projects\Pages\ValidationProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\ProjectRevision;
use App\Models\User;
use App\Services\ProjectSchedulePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class AdminProjectResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_urls_use_the_reference_number(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Route Key Project',
            'reference_number' => '20930',
        ]);

        $this->assertSame('20930', $project->getRouteKey());
        $this->assertSame('/projects/20930', ProjectResource::getUrl('view', ['record' => $project], false));
        $this->assertStringStartsWith(
            '/projects/20930/pdf/schedule',
            route('projects.pdf.schedule', [
                'project' => $project,
                'revision' => $project->active_revision_id,
            ], false),
        );

        $this->get(ProjectResource::getUrl('view', ['record' => $project], false))
            ->assertOk()
            ->assertSee('Route Key Project');
    }

    public function test_legacy_project_id_urls_still_resolve(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Legacy Route Project',
            'reference_number' => 'LEGACY-20930',
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertSee('Legacy Route Project');

        $this->get('/projects/'.$project->id)
            ->assertOk()
            ->assertSee('Legacy Route Project');
    }

    public function test_project_revisions_progress_from_p0_to_r1_and_r2(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();

        $this->assertSame(0, $project->revision);
        $this->assertSame('P0', $project->activeRevision->label());

        $component = Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertSee('P0')
            ->set('revisionsModalOpen', true)
            ->assertSee('Create New Revision')
            ->call('createNewRevision');

        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame('R1', $project->fresh()->activeRevision->label());

        $component
            ->set('revisionsModalOpen', true)
            ->assertSee('R1')
            ->call('createNewRevision');

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

        $this->assertSame(
            'NHS Eastpoint Centre',
            ProjectForm::titleCaseProjectName('NHS EASTPOINT CENTRE'),
        );

        $this->assertSame(
            'UK Hospital LV Room',
            ProjectForm::titleCaseProjectName('UK HOSPITAL LV ROOM'),
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

    public function test_project_table_shows_details_pencil_before_copy_action(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();

        Livewire::test(ListProjects::class)
            ->assertTableActionsExistInOrder(['editProject', 'duplicate'])
            ->assertTableActionVisible('editProject', $project)
            ->assertTableActionHasIcon('editProject', 'heroicon-o-pencil', $project)
            ->assertTableActionVisible('duplicate', $project);
    }

    public function test_locked_project_details_drawers_remain_visible_but_cannot_save(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Locked Details Project',
            'reference_number' => 'LOCKED-DETAILS',
        ]);

        $project->activeRevision->update([
            'validated' => true,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $data = [
            'name' => 'Should Not Save',
            'reference_number' => 'LOCKED-DETAILS',
            'customer_name' => $project->customer_name,
            'contractor' => $project->contractor,
            'site_location' => $project->site_location,
            'owner_email' => $project->owner_email,
            'created_by_email' => $project->created_by_email,
            'department' => $project->department,
            'date' => $project->date?->format('Y-m-d'),
            'revision' => $project->revision,
            'visibility' => $project->visibility->value,
            'branch_name' => $project->branch_name,
            'cover_percentage' => $project->cover_percentage,
            'value' => $project->value,
            'quote_notes' => $project->quote_notes,
            'internal_notes' => $project->internal_notes,
            'general_notes' => $project->general_notes,
        ];

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertActionVisible('editProject')
            ->callAction('editProject', $data)
            ->assertForbidden();

        Livewire::test(ListProjects::class)
            ->assertTableActionVisible('editProject', $project)
            ->callTableAction('editProject', $project, $data)
            ->assertForbidden();

        $this->assertSame('Locked Details Project', $project->fresh()->name);
    }

    public function test_approved_revision_lock_is_prominent_and_explains_blocked_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Approved Lock Project',
        ]);
        $area = $project->activeRevision->areas()->first();

        $project->activeRevision->update([
            'validated' => true,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertSee('Approved Lock Project')
            ->assertSee('Approved - locked')
            ->call('notifyApprovedRevisionLocked')
            ->assertNotified('Revision locked');

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('addBlankLine', $area->id)
            ->assertOk()
            ->assertNotified('Revision locked');

        $this->assertSame(0, $area->lines()->count());
    }

    public function test_stale_project_page_does_not_show_403_when_revision_was_approved_elsewhere(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Stale Lock Project',
        ]);
        $area = $project->activeRevision->areas()->first();

        $component = Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertSee('Stale Lock Project')
            ->assertDontSee('Approved - locked');

        $project->activeRevision->update([
            'validated' => true,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $component
            ->call('addBlankLine', $area->id)
            ->assertOk()
            ->assertNotified('Revision locked');

        $this->assertSame(0, $area->lines()->count());
    }

    public function test_project_list_hides_archived_projects_until_status_filter_requests_them(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $draftProject = Project::factory()->for($admin)->create([
            'status' => ProjectStatus::Draft,
        ]);

        $archivedProject = Project::factory()->for($admin)->create([
            'status' => ProjectStatus::Archived,
        ]);

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$draftProject])
            ->assertCanNotSeeTableRecords([$archivedProject])
            ->filterTable('status', [ProjectStatus::Archived])
            ->assertCanSeeTableRecords([$archivedProject])
            ->assertCanNotSeeTableRecords([$draftProject]);
    }

    public function test_project_status_tracks_draft_in_progress_and_approval_requested_states(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();

        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);

        $project->activeRevision->areas()->first()->lines()->create([
            'code' => 'STATUS-SKU',
            'description' => 'Status product',
            'qty' => 1,
            'type' => ProjectLineType::Standard->value,
            'unit_price' => 10,
            'sort_order' => 0,
        ]);

        $this->assertSame(ProjectStatus::InProgress, $project->fresh()->status);

        $project->fresh()->markApprovalRequested();

        $this->assertSame(ProjectStatus::ApprovalRequested, $project->fresh()->status);
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

        Product::factory()->create(['sku' => 'VALID-SKU', 'price' => 10]);

        $groundFloor->lines()->createMany([
            [
                'code' => 'VALID-SKU',
                'description' => 'First valid product',
                'qty' => 1,
                'unit_price' => 10,
                'type' => ProjectLineType::Standard->value,
                'sort_order' => 0,
            ],
            [
                'code' => 'valid-sku',
                'description' => 'Duplicate valid product',
                'qty' => 1,
                'unit_price' => 10,
                'type' => ProjectLineType::Standard->value,
                'sort_order' => 1,
            ],
            [
                'code' => 'MISSING-SKU',
                'description' => 'Missing product',
                'qty' => 1,
                'unit_price' => 10,
                'type' => ProjectLineType::Custom->value,
                'sort_order' => 2,
            ],
        ]);

        $firstFloor->lines()->create([
            'code' => 'VALID-SKU',
            'description' => 'Valid in another area',
            'qty' => 1,
            'unit_price' => 10,
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
            'unit_price' => 10,
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
            ->set('pastedProductData', "2→TAB-SKU→\"Discarded quoted\nmultiline description\"→12.50\n3\tSECOND-SKU\tDiscarded description\t24.75\n1\tcustom-lower\tUnknown product\t5.00")
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

    public function test_admin_can_paste_technical_products_by_area(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $existingArea = $project->activeRevision->areas()->first();
        $existingArea->lines()->create([
            'code' => 'OLD-SKU',
            'description' => 'Existing line',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);
        $tabProduct = Product::factory()->create([
            'sku' => 'TAB-SKU',
            'product_name' => 'Tab Product',
            'description' => 'Tab Product Description',
            'price' => 12.34,
        ]);
        $fallbackProduct = Product::factory()->create([
            'sku' => 'FALLBACK-SKU',
            'product_name' => 'Fallback Product',
            'description' => 'Fallback Product Description',
            'price' => 34.56,
        ]);
        $commaProduct = Product::factory()->create([
            'sku' => 'COMMA-SKU',
            'product_name' => 'Comma Product',
            'description' => 'Comma Product Description',
            'price' => 56.78,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $existingArea->id)
            ->set('pasteProductsMode', 'technical')
            ->set('pastedProductData', implode("\n", [
                "Ground Floor\t\t\t",
                'TAB-SKU→R1→2→Discarded pasted description',
                "FALLBACK-SKU\tFB\t5\t",
                "special-sku\t3\tPasted special description",
                '',
                'First Floor',
                'COMMA-SKU,REF2,4,Discarded comma description',
            ]))
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', false);

        $this->assertDatabaseMissing('project_areas', ['id' => $existingArea->id]);

        $areas = ProjectArea::where('project_revision_id', $project->active_revision_id)
            ->with('lines')
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $areas);
        $this->assertSame('Ground Floor', $areas[0]->name);
        $this->assertSame('First Floor', $areas[1]->name);

        $groundFloorLines = $areas[0]->lines;
        $this->assertCount(3, $groundFloorLines);
        $this->assertSame($tabProduct->id, $groundFloorLines[0]->product_id);
        $this->assertSame('TAB-SKU', $groundFloorLines[0]->code);
        $this->assertSame('R1', $groundFloorLines[0]->ref);
        $this->assertSame('Discarded pasted description', $groundFloorLines[0]->description);
        $this->assertSame(2, $groundFloorLines[0]->qty);
        $this->assertSame('12.34', $groundFloorLines[0]->unit_price);
        $this->assertSame(ProjectLineType::Standard, $groundFloorLines[0]->type);

        $this->assertSame($fallbackProduct->id, $groundFloorLines[1]->product_id);
        $this->assertSame('FALLBACK-SKU', $groundFloorLines[1]->code);
        $this->assertSame('FB', $groundFloorLines[1]->ref);
        $this->assertSame('Fallback Product Description', $groundFloorLines[1]->description);
        $this->assertSame(5, $groundFloorLines[1]->qty);
        $this->assertSame('34.56', $groundFloorLines[1]->unit_price);
        $this->assertSame(ProjectLineType::Standard, $groundFloorLines[1]->type);

        $this->assertNull($groundFloorLines[2]->product_id);
        $this->assertSame('SPECIAL-SKU', $groundFloorLines[2]->code);
        $this->assertNull($groundFloorLines[2]->ref);
        $this->assertSame('Pasted special description', $groundFloorLines[2]->description);
        $this->assertSame(3, $groundFloorLines[2]->qty);
        $this->assertNull($groundFloorLines[2]->unit_price);
        $this->assertSame(ProjectLineType::Custom, $groundFloorLines[2]->type);

        $firstFloorLine = $areas[1]->lines->first();
        $this->assertSame($commaProduct->id, $firstFloorLine->product_id);
        $this->assertSame('COMMA-SKU', $firstFloorLine->code);
        $this->assertSame('REF2', $firstFloorLine->ref);
        $this->assertSame(4, $firstFloorLine->qty);
        $this->assertSame('Discarded comma description', $firstFloorLine->description);
    }

    public function test_technical_paste_validates_before_replacing_existing_areas(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $existingArea = $project->activeRevision->areas()->first();
        $existingLine = $existingArea->lines()->create([
            'code' => 'OLD-SKU',
            'description' => 'Existing line',
            'qty' => 1,
            'type' => ProjectLineType::Custom->value,
            'sort_order' => 0,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('openPasteProductsModal', $existingArea->id)
            ->set('pasteProductsMode', 'technical')
            ->set('pastedProductData', "Ground Floor\nBROKEN-SKU\tREF\tbad\tDescription")
            ->call('addPastedProducts')
            ->assertSet('pasteProductsModalOpen', true)
            ->assertSet('pasteProductsError', 'Technical paste row 2 in "Ground Floor" is invalid: QTY must be a whole number greater than zero.');

        $this->assertDatabaseHas('project_areas', [
            'id' => $existingArea->id,
            'name' => $existingArea->name,
        ]);
        $this->assertDatabaseHas('project_lines', [
            'id' => $existingLine->id,
            'code' => 'OLD-SKU',
        ]);
    }

    public function test_project_line_status_reflects_validation_state(): void
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
        $area->lines()->create([
            'code' => 'PENDING-SKU',
            'description' => 'Pending product',
            'qty' => 1,
            'type' => ProjectLineType::Standard->value,
            'unit_price' => 12.00,
            'status' => 'Priced',
            'sort_order' => 1,
        ]);
        $area->lines()->create([
            'code' => 'FLAGGED-SKU',
            'description' => 'Flagged product',
            'qty' => 1,
            'type' => ProjectLineType::Standard->value,
            'unit_price' => 14.00,
            'status' => 'Priced',
            'approved' => true,
            'validation_flagged' => true,
            'sort_order' => 2,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertSee('Approved')
            ->assertSee('Pending')
            ->assertSee('Flagged')
            ->assertDontSee('Priced');
    }

    public function test_project_line_status_column_is_hidden_without_price_visibility(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::factory()->for($user)->create();
        $area = $project->activeRevision->areas()->first();

        $area->lines()->create([
            'code' => 'HIDDEN-STATUS-SKU',
            'description' => 'Hidden status product',
            'qty' => 1,
            'type' => ProjectLineType::Standard->value,
            'unit_price' => 10.00,
            'status' => 'Pending',
            'sort_order' => 0,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->assertSee('HIDDEN-STATUS-SKU')
            ->assertSee('Notes')
            ->assertDontSeeHtml('<div>Status</div>')
            ->assertDontSee('Pending')
            ->assertDontSee('Unit Price');
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

            public function contentFromBuilder(object $builder): string
            {
                return AdminProjectResourceTest::pdfFixtureContent();
            }
        });

        $response = $this->get(route('projects.pdf.schedule', [
            'project' => $project,
            'revision' => $revision->id,
        ]));

        $response
            ->assertOk()
            ->assertDownload('schedule-PDF-REF-P0.pdf');

        $this->assertPdfPageCount($response->baseResponse->getFile()->getPathname(), 2);
        File::delete($response->baseResponse->getFile()->getPathname());

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
            ->assertSee('Quote status')
            ->assertSee('Approval Required')
            ->assertSee('Validation')
            ->assertSee('Not passed')
            ->assertSee('All outputs include links to product datasheets.')
            ->assertSee('Generate a document')
            ->assertSee('Quote PDF')
            ->assertSee('Schedule PDF')
            ->assertSee('Download as CSV')
            ->assertSee(e(route('projects.pdf.schedule', [
                'project' => $project,
                'revision' => $project->active_revision_id,
                'salesforce_upload' => true,
            ])), false)
            ->assertSee(route('projects.export.unpriced-csv', [
                'project' => $project,
                'revision' => $project->active_revision_id,
            ]), false);
    }

    public function test_output_pdf_urls_include_datasheet_flag_when_enabled(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'reference_number' => 'DS-URL',
        ]);
        $project->activeRevision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $component = Livewire::test(OutputProject::class, ['record' => $project->id]);

        $this->assertStringNotContainsString('include_datasheets', $component->instance()->getSchedulePdfUrl());

        $component
            ->set('includeScheduleDatasheets', true)
            ->set('includeQuoteDatasheets', true);

        $this->assertStringContainsString('include_datasheets=1', $component->instance()->getSchedulePdfUrl());
        $this->assertStringContainsString('include_datasheets=1', $component->instance()->getQuotePdfUrl());
    }

    public function test_pdf_progress_endpoint_returns_only_the_authenticated_users_progress(): void
    {
        $admin = User::factory()->admin()->create();
        $otherUser = User::factory()->create();
        $token = 'progress-token-123';

        Cache::put('pdf-progress:'.$admin->id.':'.$token, [
            'percent' => 54,
            'message' => 'Page 2 of 5 generated.',
            'complete' => false,
        ], now()->addMinutes(5));

        Cache::put('pdf-progress:'.$otherUser->id.':'.$token, [
            'percent' => 99,
            'message' => 'Wrong user progress.',
            'complete' => true,
        ], now()->addMinutes(5));

        $this->actingAs($admin)
            ->getJson(route('pdf.progress', ['token' => $token]))
            ->assertOk()
            ->assertExactJson([
                'percent' => 54,
                'message' => 'Page 2 of 5 generated.',
                'complete' => false,
            ]);
    }

    public function test_schedule_pdf_can_append_remote_datasheets(): void
    {
        config([
            'services.datasheets.endpoint' => 'https://tamlite.co.uk/ci_index.php/download_schedule',
            'services.datasheets.public_base_url' => 'https://tamlite.co.uk/pdfmerge',
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Datasheet Project',
            'reference_number' => 'DS-001',
        ]);
        $area = $project->activeRevision->areas()->first();
        $product = Product::factory()->create([
            'site' => 'tamlite',
            'sku' => 'TAM-SKU',
        ]);

        $area->lines()->createMany([
            [
                'product_id' => $product->id,
                'code' => 'TAM-SKU',
                'ref' => 'A1',
                'description' => 'Tamlite line',
                'qty' => 2,
                'type' => ProjectLineType::Standard->value,
                'notes' => 'Fit near door',
                'sort_order' => 0,
            ],
            [
                'code' => 'CUSTOM-SKU',
                'ref' => '',
                'description' => 'Custom line',
                'qty' => 1,
                'type' => ProjectLineType::Custom->value,
                'notes' => null,
                'sort_order' => 1,
            ],
        ]);

        $this->instance(ProjectSchedulePdfService::class, new class
        {
            public function filename(Project $project, ProjectRevision $revision): string
            {
                return 'schedule-DS-001-P0.pdf';
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

            public function contentFromBuilder(object $builder): string
            {
                return AdminProjectResourceTest::pdfFixtureContent();
            }
        });

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://tamlite.co.uk/ci_index.php/download_schedule') {
                return Http::response(str_repeat(' ', 1024).implode("\n", [
                    '{"step":0,"total":2,"message":"Page 0 of 2 generated."}',
                    '{"step":1,"total":2,"message":"Page 1 of 2 generated."}',
                    '{"complete":true,"message":"Processing completed!","filename":"datasheet-project.pdf"}',
                ]));
            }

            if ($request->url() === 'https://tamlite.co.uk/pdfmerge/datasheet-project.pdf') {
                return Http::response(self::pdfFixtureContent(), 200, [
                    'Content-Type' => 'application/pdf',
                ]);
            }

            return Http::response([], 500);
        });

        $response = $this->get(route('projects.pdf.schedule', [
            'project' => $project,
            'revision' => $project->active_revision_id,
            'include_datasheets' => true,
        ]));

        $response
            ->assertOk()
            ->assertDownload('schedule-DS-001-P0-with-datasheets.pdf');

        $this->assertPdfPageCount($response->baseResponse->getFile()->getPathname(), 3);
        File::delete($response->baseResponse->getFile()->getPathname());

        $datasheetRequest = Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->first(fn (Request $request): bool => $request->url() === 'https://tamlite.co.uk/ci_index.php/download_schedule');

        $this->assertNotNull($datasheetRequest);
        $this->assertFalse($datasheetRequest->isJson());
        $this->assertSame('datasheet-project', $datasheetRequest->data()['project_slug']);
        $this->assertSame(0, $datasheetRequest->data()['project_version']);
        $this->assertSame('Datasheet Project', $datasheetRequest->data()['info_project_name']);
        $this->assertSame('DS-001', $datasheetRequest->data()['info_project_id']);
        $this->assertTrue($datasheetRequest->data()['include_datasheets']);
        $this->assertFalse($datasheetRequest->data()['include_schedule']);
        $this->assertJson($datasheetRequest->data()['skus']);
        $this->assertSame([
            [
                'qty' => 2,
                'sku' => 'TAM-SKU',
                'ref' => 'A1',
                'note' => 'Fit near door',
                'brand' => 'Tamlite',
            ],
            [
                'qty' => 1,
                'sku' => 'CUSTOM-SKU',
                'ref' => '',
                'note' => null,
                'brand' => 'Custom',
            ],
        ], json_decode($datasheetRequest->data()['skus'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_quote_pdf_can_append_remote_datasheets(): void
    {
        config([
            'services.datasheets.endpoint' => 'https://tamlite.co.uk/ci_index.php/download_schedule',
            'services.datasheets.public_base_url' => 'https://tamlite.co.uk/pdfmerge',
        ]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Quote Datasheet Project',
            'reference_number' => 'QDS-001',
        ]);
        $project->activeRevision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $admin->id,
            'status' => ProjectRevisionStatus::Approved,
        ]);

        $this->instance(ProjectSchedulePdfService::class, new class
        {
            public function quoteFilename(Project $project, ProjectRevision $revision): string
            {
                return 'quote-QDS-001-P0.pdf';
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

            public function contentFromBuilder(object $builder): string
            {
                return AdminProjectResourceTest::pdfFixtureContent();
            }
        });

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://tamlite.co.uk/ci_index.php/download_schedule') {
                return Http::response('{"complete":true,"message":"Processing completed!","filename":"quote-datasheets.pdf"}');
            }

            if ($request->url() === 'https://tamlite.co.uk/pdfmerge/quote-datasheets.pdf') {
                return Http::response(self::pdfFixtureContent(), 200, [
                    'Content-Type' => 'application/pdf',
                ]);
            }

            return Http::response([], 500);
        });

        $response = $this->get(route('projects.pdf.quote', [
            'project' => $project,
            'revision' => $project->active_revision_id,
            'include_datasheets' => true,
        ]));

        $response
            ->assertOk()
            ->assertDownload('quote-QDS-001-P0-with-datasheets.pdf');

        $this->assertPdfPageCount($response->baseResponse->getFile()->getPathname(), 3);
        File::delete($response->baseResponse->getFile()->getPathname());

        $this->assertSame(ProjectStatus::Quoted, $project->fresh()->status);
    }

    public function test_authorized_user_can_request_quote_approval(): void
    {
        $salesUser = User::factory()->sales()->create();
        $this->actingAs($salesUser);

        $project = Project::factory()->for($salesUser)->create([
            'name' => 'Approval Request Project',
        ]);
        $project->activeRevision->update([
            'validated' => true,
            'validated_at' => now(),
            'validated_by' => $salesUser->id,
        ]);

        Livewire::test(OutputProject::class, ['record' => $project->id])
            ->assertSee('Approval Required')
            ->assertSee('Request Approval')
            ->call('requestQuoteApproval')
            ->assertSee('Approval has been requested')
            ->assertSee('Requested');

        $this->assertSame(ProjectStatus::ApprovalRequested, $project->fresh()->status);
        $this->assertDatabaseHas(ActivityLog::class, [
            'project_id' => $project->id,
            'action_type' => 'quote_approval.requested',
            'revision_number' => 0,
        ]);

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListActivityLogs::class)
            ->assertSee('Requested quote approval')
            ->assertSee('P0');
    }

    public function test_any_output_user_can_request_quote_approval_without_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = Project::factory()->for($user)->create();

        Livewire::test(OutputProject::class, ['record' => $project->id])
            ->assertSee('Approval Required')
            ->assertSee('Request Approval')
            ->assertDontSee('View Validation')
            ->assertDontSee('Validation must pass before outputs can be generated.')
            ->call('requestQuoteApproval')
            ->assertSee('Requested');

        $this->assertFalse($project->activeRevision->fresh()->validated);
        $this->assertSame(ProjectStatus::ApprovalRequested, $project->fresh()->status);
    }

    public function test_admin_can_switch_between_single_pdf_and_document_pack_tabs(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();

        $component = Livewire::test(OutputProject::class, ['record' => $project->id])
            ->assertSet('outputTab', 'single')
            ->assertSee('Quote status')
            ->assertSee('Quick Output')
            ->assertSee('Document Packs')
            ->assertSee('Quote with pricing.')
            ->assertSee('Schedule without pricing. Always available.')
            ->assertSee('About datasheets')
            ->assertDontSee('Learn more')
            ->assertDontSee('Build a reusable pack, drag documents into the required order');

        $this->assertLessThan(
            strpos($component->html(), 'role="tablist"'),
            strpos($component->html(), 'Quote status'),
        );

        $component
            ->set('outputTab', 'packs')
            ->assertSee('Quote status')
            ->assertSee('Build a reusable pack, drag documents into the required order')
            ->assertDontSee('Quote with pricing.')
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

            public function contentFromBuilder(object $builder): string
            {
                return AdminProjectResourceTest::pdfFixtureContent();
            }
        });

        $response = $this->get(route('projects.pdf.quote', [
            'project' => $project,
            'revision' => $revision->id,
        ]));

        $response
            ->assertOk()
            ->assertDownload('quote-QUOTE-REF-P0.pdf');

        $this->assertPdfPageCount($response->baseResponse->getFile()->getPathname(), 2);
        File::delete($response->baseResponse->getFile()->getPathname());

        $this->assertSame(ProjectStatus::Quoted, $project->fresh()->status);
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

    public function test_schedule_legal_blurb_is_grouped_with_final_line_item_table(): void
    {
        $user = User::factory()->create(['name' => 'PDF User']);
        $project = Project::factory()->for($user)->create();
        $revision = $project->activeRevision;
        $area = $revision->areas()->firstOrFail();

        ProjectLine::create([
            'project_area_id' => $area->id,
            'code' => 'AST110NWD',
            'description' => 'ASTRO 10W - 4000K',
            'qty' => 32,
            'type' => ProjectLineType::Standard,
            'sort_order' => 0,
        ]);

        ProjectLine::create([
            'project_area_id' => $area->id,
            'code' => 'AST220NWM3',
            'description' => 'ASTRO 20W - 4000K',
            'qty' => 3,
            'type' => ProjectLineType::Standard,
            'sort_order' => 1,
        ]);

        $html = view('pdfs.schedule', [
            'project' => $project->load('user'),
            'revision' => $revision,
            'areas' => ProjectArea::where('project_revision_id', $revision->id)->with('lines')->get(),
            'documentTitle' => 'Lighting Schedule',
            'showPrices' => false,
        ])->render();

        $this->assertStringContainsString('class="final-line-and-legal"', $html);
        $this->assertStringContainsString('class="legal-blurb-row"', $html);
        $this->assertLessThan(
            strpos($html, 'class="legal-blurb-row"'),
            strpos($html, 'AST220NWM3'),
        );
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

    public function test_activity_logs_table_formats_changed_values_for_readability(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'reference_number' => 'REF-004',
            'revision' => 1,
        ]);

        ActivityLog::create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'action_type' => 'project.updated',
            'user_email_snapshot' => $admin->email,
            'project_name_snapshot' => $project->name,
            'payload' => [
                'status' => [
                    'old' => 'in_progress',
                    'new' => 'approval_requested',
                ],
            ],
        ]);

        Livewire::test(ListActivityLogs::class)
            ->assertSee('Changed')
            ->assertSee('Status')
            ->assertSee('In Progress')
            ->assertSee('Approval Requested')
            ->assertDontSee('in_progress')
            ->assertDontSee('approval_requested');
    }

    public function test_activity_logs_table_hides_note_contents_for_line_updates(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'reference_number' => 'REF-005',
            'revision' => 1,
        ]);

        ActivityLog::create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'action_type' => 'line.updated',
            'user_email_snapshot' => $admin->email,
            'project_name_snapshot' => $project->name,
            'payload' => [
                'code' => 'SKU-001',
                'changes' => [
                    'validation_note' => [
                        'old' => 'Approved: SKU "SKU-001" has no product RRP. Review the quote price before approving.',
                        'new' => '',
                    ],
                    'notes' => [
                        'old' => '',
                        'new' => 'Long private project note that should not be displayed in the log table.',
                    ],
                ],
            ],
        ]);

        Livewire::test(ListActivityLogs::class)
            ->assertSee('SKU-001')
            ->assertSee('Cleared')
            ->assertSee('Validation Note')
            ->assertSee('Added')
            ->assertSee('Notes')
            ->assertDontSee('Review the quote price before approving')
            ->assertDontSee('Long private project note');
    }

    public function test_activity_logs_table_shows_project_name_fallback_when_reference_is_missing(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'name' => 'Local Project Without Reference',
            'reference_number' => null,
            'revision' => 2,
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
            ->assertSee('Local Projec...')
            ->assertSee('R2')
            ->assertDontSee('No project');
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

    public static function pdfFixtureContent(): string
    {
        return <<<'PDF'
%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>
endobj
4 0 obj
<< /Length 40 >>
stream
BT /F1 12 Tf 20 100 Td (Test PDF) Tj ET
endstream
endobj
5 0 obj
<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>
endobj
xref
0 6
0000000000 65535 f
0000000009 00000 n
0000000058 00000 n
0000000115 00000 n
0000000231 00000 n
0000000321 00000 n
trailer
<< /Size 6 /Root 1 0 R >>
startxref
391
%%EOF
PDF;
    }

    private function assertPdfPageCount(string $path, int $expectedPages): void
    {
        $process = new Process([(string) config('document-packs.qpdf_binary', 'qpdf'), '--show-npages', $path]);
        $process->mustRun();

        $this->assertSame((string) $expectedPages, trim($process->getOutput()));
    }
}
