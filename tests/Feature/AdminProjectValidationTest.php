<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Filament\Resources\Projects\Pages\ValidationProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Revision validated');

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
            ->assertSee('Revision validated');

        $this->assertTrue($line->fresh()->approved);
        $this->assertTrue($project->activeRevision->fresh()->validated);

        $component
            ->call('undoIssueApproval', $issueKey)
            ->assertSee('1 unresolved issue');

        $this->assertFalse($line->fresh()->approved);
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

    public function test_admin_can_merge_duplicate_skus_and_validate_the_revision(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        Product::factory()->create(['sku' => 'DUPLICATE-SKU', 'price' => null]);
        $firstLine = $this->createLine($project, 'DUPLICATE-SKU', 2, 0);
        $secondLine = $this->createLine($project, 'DUPLICATE-SKU', 3, 1);
        $issueKey = "duplicate-{$project->activeRevision->areas()->first()->id}-DUPLICATE-SKU";

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('mergeIssue', $issueKey)
            ->assertSee('Revision validated');

        $this->assertSame(1, ProjectLine::whereIn('id', [$firstLine->id, $secondLine->id])->count());
        $this->assertSame(5, $firstLine->fresh()->qty);
        $this->assertTrue($firstLine->fresh()->approved);
        $this->assertTrue($project->activeRevision->fresh()->validated);
    }

    public function test_validated_revision_is_locked_but_new_revision_is_editable(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $project = Project::factory()->for($admin)->create();
        $product = Product::factory()->create(['price' => 12.34]);
        $line = $this->createLine($project, $product->sku, unitPrice: 12.34);

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('runValidation');

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('updateLineField', $line->id, 'qty', 10)
            ->assertForbidden();

        $this->assertSame(1, $line->fresh()->qty);

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
            ->assertSee('Approved')
            ->assertSee('Revision validated');

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
            ->assertSee('Revision validated');

        $this->assertSame('42.50', $line->fresh()->unit_price);
        $this->assertTrue($line->fresh()->approved);
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
