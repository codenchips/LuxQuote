<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Enums\ProjectRevisionStatus;
use App\Filament\Resources\Projects\Pages\ValidationProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AdminProjectValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_revisions_and_lines_are_unvalidated_and_unapproved_by_default(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $line = $this->createLine($project, 'DEFAULT-SKU');

        $this->assertFalse($project->activeRevision->validated);
        $this->assertSame(ProjectRevisionStatus::Draft, $project->activeRevision->status);
        $this->assertFalse($line->approved);
    }

    public function test_running_validation_validates_a_revision_without_warnings(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create(['price' => 12.34]);
        $line = $this->createLine($project, $product->sku, unitPrice: 12.34);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('runValidation')
            ->assertSee('Ready to approve');

        $revision = $project->activeRevision->fresh();

        $this->assertTrue($revision->validated);
        $this->assertTrue($line->fresh()->approved);
        $this->assertSame($admin->id, $revision->validated_by);
        $this->assertNotNull($revision->validated_at);
    }

    public function test_admin_can_approve_and_undo_a_warning(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $line = $this->createLine($project, 'MISSING-SKU');
        $issueKey = "missing-product-{$line->id}";

        $component = Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('approveIssue', $issueKey)
            ->assertSee('Approved')
            ->assertSee('Ready to approve');

        $this->assertTrue($line->fresh()->approved);
        $this->assertTrue($project->activeRevision->fresh()->validated);

        $component
            ->call('undoIssueApproval', $issueKey)
            ->assertSee('1 unresolved issue');

        $this->assertFalse($line->fresh()->approved);
        $this->assertFalse($project->activeRevision->fresh()->validated);
    }

    public function test_validation_page_shows_clean_product_lines_as_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create([
            'sku' => 'VALID-SKU',
            'price' => 12.34,
        ]);
        $validLine = $this->createLine($project, $product->sku, unitPrice: 12.34);
        $missingLine = $this->createLine($project, 'MISSING-SKU', sortOrder: 1);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->assertSee('Issues (1)')
            ->assertSee("SKU \"{$missingLine->code}\" was not found in the product catalogue.")
            ->assertSee('Validated (1)')
            ->assertSee($validLine->code)
            ->assertSee('Resolved: no current validation issues.')
            ->assertDontSee('Area:');
    }

    public function test_approved_issue_moves_to_validated_table_and_can_be_flagged_again(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $line = $this->createLine($project, 'MISSING-SKU');
        $issueKey = "missing-product-{$line->id}";

        $component = Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->assertSee('Issues (1)')
            ->call('approveIssue', $issueKey)
            ->assertSee('Issues (0)')
            ->assertSee('Validated (1)')
            ->assertSee('Approved: SKU "MISSING-SKU" was not found in the product catalogue.')
            ->assertSee('Flag Issue')
            ->assertDontSee('Undo');

        $this->assertTrue($line->fresh()->approved);
        $this->assertFalse($line->fresh()->validation_flagged);

        $component
            ->call('flagValidatedLine', $line->id)
            ->assertSee('Issues (1)')
            ->assertSee('SKU "MISSING-SKU" was not found in the product catalogue.');

        $this->assertFalse($line->fresh()->approved);
        $this->assertFalse($line->fresh()->validation_flagged);
    }

    public function test_clean_validated_line_can_be_manually_flagged_as_an_issue(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create([
            'sku' => 'VALID-SKU',
            'price' => 12.34,
        ]);
        $line = $this->createLine($project, $product->sku, unitPrice: 12.34);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->assertSee('Validated (1)')
            ->call('flagValidatedLine', $line->id)
            ->assertSee('Issues (1)')
            ->assertSee('SKU "VALID-SKU" has been manually flagged for review.');

        $this->assertFalse($line->fresh()->approved);
        $this->assertTrue($line->fresh()->validation_flagged);
        $this->assertFalse($project->activeRevision->fresh()->validated);
    }

    public function test_run_validation_invalidates_a_revision_when_a_new_warning_appears(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create(['price' => 12.34]);
        $line = $this->createLine($project, $product->sku, unitPrice: 12.34);

        $component = Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('runValidation');

        $this->assertTrue($line->fresh()->approved);
        $this->assertNull($line->fresh()->approved_by);
        $this->assertTrue($project->activeRevision->fresh()->validated);

        $product->delete();

        $component
            ->call('runValidation')
            ->assertSee('1 unresolved issue');

        $this->assertFalse($project->activeRevision->fresh()->validated);
    }

    public function test_admin_can_approve_and_lock_revision_once_it_is_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create(['price' => 12.34]);
        $this->createLine($project, $product->sku, unitPrice: 12.34);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->assertSee('Approve Revision')
            ->assertSet('approveRevisionModalOpen', false)
            ->call('openApproveRevisionModal')
            ->assertForbidden();

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('approveRevision')
            ->assertForbidden();

        $this->assertSame(ProjectRevisionStatus::Draft, $project->activeRevision->fresh()->status);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('runValidation')
            ->assertSee('Ready to approve')
            ->assertSee('Approve Revision')
            ->assertDontSee('Project is approved and locked')
            ->call('openApproveRevisionModal')
            ->assertSet('approveRevisionModalOpen', true)
            ->assertSee('Approve and lock this revision?')
            ->call('approveRevision')
            ->assertSet('approveRevisionModalOpen', false)
            ->assertSee('Project is approved and locked')
            ->assertDontSee('Approve Revision')
            ->assertDontSee('Run Validation');

        $this->assertSame(ProjectRevisionStatus::Approved, $project->activeRevision->fresh()->status);
    }

    public function test_approving_salesforce_revision_updates_opportunity_amount(): void
    {
        config(['services.salesforce.url' => 'https://example.my.salesforce.com']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create([
            'salesforce_project' => true,
            'salesforce_id' => '006000000000001AAA',
        ]);
        $firstProduct = Product::factory()->create([
            'sku' => 'VALUE-ONE',
            'price' => 10.00,
        ]);
        $secondProduct = Product::factory()->create([
            'sku' => 'VALUE-TWO',
            'price' => 5.50,
        ]);

        $this->createLine($project, $firstProduct->sku, qty: 2, unitPrice: 10.00);
        $this->createLine($project, $secondProduct->sku, qty: 3, sortOrder: 1, unitPrice: 5.50);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/services/oauth2/token')) {
                return Http::response([
                    'access_token' => 'test-token',
                    'instance_url' => 'https://example.my.salesforce.com',
                ]);
            }

            if (
                $request->method() === 'PATCH'
                && str_contains($request->url(), '/services/data/v65.0/sobjects/Opportunity/006000000000001AAA')
            ) {
                return Http::response([], 204);
            }

            return Http::response([], 500);
        });

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('runValidation')
            ->call('approveRevision')
            ->assertNotified('Salesforce value updated');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/services/data/v65.0/sobjects/Opportunity/006000000000001AAA')
            && $request->data()['Amount'] === 36.5);
    }

    public function test_approved_revision_rejects_validation_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create(['price' => 12.34]);
        $this->createLine($project, $product->sku, unitPrice: 12.34);

        $component = Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('runValidation')
            ->call('approveRevision');

        $this->assertSame(ProjectRevisionStatus::Approved, $project->activeRevision->fresh()->status);

        $product->delete();

        $component
            ->call('runValidation')
            ->assertForbidden();

        $this->assertSame(ProjectRevisionStatus::Approved, $project->activeRevision->fresh()->status);
    }

    public function test_admin_can_merge_duplicate_skus_and_validate_the_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        Product::factory()->create(['sku' => 'DUPLICATE-SKU', 'price' => null]);
        $firstLine = $this->createLine($project, 'DUPLICATE-SKU', 2, 0);
        $secondLine = $this->createLine($project, 'DUPLICATE-SKU', 3, 1);
        $issueKey = "duplicate-{$project->activeRevision->areas()->first()->id}-DUPLICATE-SKU";

        $component = Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('mergeIssue', $issueKey)
            ->assertSee('Ready to approve')
            ->assertSee('Issues (0)')
            ->assertSee('Validated (1)')
            ->assertSee('Resolved: SKU "DUPLICATE-SKU" appears 2 times in this area.');

        $this->assertSame(1, ProjectLine::whereIn('id', [$firstLine->id, $secondLine->id])->count());
        $this->assertSame(5, $firstLine->fresh()->qty);
        $this->assertTrue($firstLine->fresh()->approved);
        $this->assertTrue($project->activeRevision->fresh()->validated);

        $component
            ->call('flagValidatedLine', $firstLine->id)
            ->assertSee('Issues (1)')
            ->assertSee('SKU "DUPLICATE-SKU" has been manually flagged for review.');

        $this->assertTrue($firstLine->fresh()->validation_flagged);
        $this->assertFalse($project->activeRevision->fresh()->validated);
    }

    public function test_revision_is_locked_only_after_approval(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create(['price' => 12.34]);
        $line = $this->createLine($project, $product->sku, unitPrice: 12.34);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('runValidation');

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('updateLineField', $line->id, 'qty', 10);

        $this->assertSame(10, $line->fresh()->qty);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('runValidation')
            ->call('approveRevision');

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('updateLineField', $line->id, 'qty', 11)
            ->assertForbidden();

        $this->assertSame(10, $line->fresh()->qty);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('createNewRevision');

        $project->refresh();
        $newRevision = $project->activeRevision;
        $newLine = $newRevision->areas()->first()->lines()->first();

        $this->assertFalse($newRevision->validated);
        $this->assertFalse($newLine->approved);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('updateLineField', $newLine->id, 'qty', 10);

        $this->assertSame(10, $newLine->fresh()->qty);
    }

    public function test_price_mismatch_is_a_validation_issue_that_can_be_approved_and_undone(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create([
            'sku' => 'PRICE-SKU',
            'price' => 12.34,
        ]);
        $line = $this->createLine($project, $product->sku, unitPrice: 10.00);
        $issueKey = "price-mismatch-{$line->id}";

        $component = Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->assertSee('1 unresolved issue')
            ->assertSee('Quote price for SKU "PRICE-SKU" does not match the product RRP.')
            ->assertSee('RRP')
            ->assertSee('Quote')
            ->call('approveIssue', $issueKey)
            ->assertSee('Ready to approve')
            ->assertSee('Issues (0)')
            ->assertSee('Validated (1)')
            ->assertSee('Approved: Quote price for SKU "PRICE-SKU" does not match the product RRP.')
            ->assertSee('Flag Issue')
            ->assertDontSee('Undo');

        $this->assertSame('10.00', $line->fresh()->unit_price);
        $this->assertTrue($line->fresh()->approved);

        $component
            ->call('undoIssueApproval', $issueKey)
            ->assertSee('1 unresolved issue');

        $this->assertFalse($line->fresh()->approved);
        $this->assertFalse($project->activeRevision->fresh()->validated);
    }

    public function test_manager_can_update_quote_price_to_resolve_price_mismatch(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create([
            'sku' => 'UPDATE-PRICE-SKU',
            'price' => 42.50,
        ]);
        $line = $this->createLine($project, $product->sku, unitPrice: 10.00);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('updateIssueQuotePrice', "price-mismatch-{$line->id}", '42.50')
            ->assertSee('Ready to approve');

        $this->assertSame('42.50', $line->fresh()->unit_price);
        $this->assertTrue($line->fresh()->approved);
        $this->assertTrue($project->activeRevision->fresh()->validated);
    }

    public function test_admin_can_match_quote_price_to_rrp_to_resolve_price_mismatch(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create([
            'sku' => 'MATCH-PRICE-SKU',
            'price' => 64.25,
        ]);
        $line = $this->createLine($project, $product->sku, unitPrice: 10.00);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->assertSee('Match')
            ->call('matchIssueQuotePrice', "price-mismatch-{$line->id}")
            ->assertSee('Ready to approve');

        $this->assertSame('64.25', $line->fresh()->unit_price);
        $this->assertTrue($line->fresh()->approved);
        $this->assertNull($line->fresh()->approved_by);
        $this->assertTrue($project->activeRevision->fresh()->validated);
    }

    private function createLine(
        Project $project,
        string $code,
        int $qty = 1,
        int $sortOrder = 0,
        float|int|string|null $unitPrice = null,
    ): ProjectLine {
        return $project->activeRevision->areas()->first()->lines()->create([
            'code' => $code,
            'description' => "{$code} description",
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'type' => ProjectLineType::Standard->value,
            'sort_order' => $sortOrder,
        ]);
    }
}
