<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Enums\ProjectRevisionStatus;
use App\Filament\Resources\Projects\Pages\ValidationProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectLine;
use App\Models\User;
use App\Services\ProjectRevisionValidator;
use App\Services\ReportingEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminProjectEachQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_shortcuts_are_case_insensitive_and_excluded_from_totals(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $project = Project::factory()->create();
        $line = $this->createLine($project, 'EACH-SKU', 4, 15);
        $this->createLine($project, 'NUMBER-SKU', 2, 10);
        $component = Livewire::test(ViewProject::class, ['record' => $project->id]);

        foreach (['E', 'e', 'Each', 'eAcH'] as $shortcut) {
            $component->call('updateLineField', $line->id, 'qty', $shortcut)->assertHasNoErrors();
            $line->refresh();
            $this->assertTrue($line->qty_is_each);
            $this->assertNull($line->qty);
            $this->assertSame('Each', $line->quantityLabel());
            $this->assertSame('15.00', $line->unit_price);
            $this->assertSame(0.0, $line->totalLineTotalForProject($project));
            $this->assertSame(2, $component->instance()->getRevisionTotals()['qty']);
            $this->assertSame(20.0, $component->instance()->getRevisionTotals()['value']);
        }

        $component->call('updateLineField', $line->id, 'qty', '3');
        $this->assertFalse($line->fresh()->qty_is_each);
        $this->assertSame(3, $line->fresh()->qty);
        $this->assertSame(5, $component->instance()->getRevisionTotals()['qty']);
        $this->assertSame(65.0, $component->instance()->getRevisionTotals()['value']);
    }

    public function test_qty_rejects_decimals_negatives_and_other_text(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $project = Project::factory()->create();
        $line = $this->createLine($project, 'VALID-QTY', 4, 15);

        foreach (['1.5', '-2', '1e3', 'text', '12abc', '2,000'] as $invalid) {
            Livewire::test(ViewProject::class, ['record' => $project->id])
                ->call('updateLineField', $line->id, 'qty', $invalid)
                ->assertHasErrors(['qty']);
            $this->assertSame(4, $line->fresh()->qty);
            $this->assertFalse($line->fresh()->qty_is_each);
        }
    }

    public function test_each_is_preserved_when_copying_lines_areas_and_revisions(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $project = Project::factory()->create();
        $line = $this->createLine($project, 'EACH-COPY', 4, 15);
        $line->update(['qty_is_each' => true]);
        $component = Livewire::test(ViewProject::class, ['record' => $project->id]);
        $component->call('duplicateLine', $line->id)
            ->call('copyArea', $line->project_area_id)
            ->call('createNewRevision');

        $lines = $project->fresh()->activeRevision->areas->flatMap->lines;
        $this->assertCount(4, $lines);
        foreach ($lines as $copiedLine) {
            $this->assertTrue($copiedLine->qty_is_each);
            $this->assertNull($copiedLine->qty);
            $this->assertSame('Each', $copiedLine->quantityLabel());
        }
    }

    public function test_each_displays_in_csv_and_quote_without_a_line_total(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $project = Project::factory()->create();
        $line = $this->createLine($project, 'EACH-OUTPUT', 4, 15);
        $line->update(['qty_is_each' => true]);
        $this->createLine($project, 'NUMBER-OUTPUT', 2, 10);
        $project->activeRevision->update(['validated' => true, 'validated_at' => now()]);

        foreach (['projects.export.csv', 'projects.export.unpriced-csv'] as $routeName) {
            $response = $this->get(route($routeName, ['project' => $project, 'revision' => $project->active_revision_id]))->assertOk();
            $rows = array_map(str_getcsv(...), array_filter(explode("\n", trim($response->streamedContent()))));
            $this->assertSame('Each', $rows[1][2]);
            if ($routeName === 'projects.export.csv') {
                $this->assertSame('15.00', $rows[1][6]);
                $this->assertSame('', $rows[1][7]);
            }
        }

        $html = view('pdfs.schedule', [
            'project' => $project->load('user'),
            'revision' => $project->activeRevision,
            'areas' => $project->activeRevision->areas()->with('lines')->get(),
            'documentTitle' => 'Lighting Quote',
            'showPrices' => true,
        ])->render();
        $this->assertStringContainsString('Each</td>', $html);
        $this->assertStringContainsString('£20.00', $html);
        $this->assertStringContainsString('£15.00', $html);
    }

    public function test_each_is_excluded_from_reporting_product_quantities_and_values(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $project = Project::factory()->for($admin)->create();
        $line = $this->createLine($project, 'EACH-REPORT', 4, 15);
        $line->update(['qty_is_each' => true]);
        $this->createLine($project, 'NUMBER-REPORT', 2, 10);
        $log = ActivityLog::create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'action_type' => 'quote_pdf.generated',
            'user_email_snapshot' => $admin->email,
            'project_name_snapshot' => $project->name,
            'revision_number' => 1,
            'payload' => [],
        ]);
        $event = app(ReportingEventService::class)->capture($log);
        $this->assertSame('20.00', $event->gross_value);
        $this->assertFalse($event->products()->where('code', 'EACH-REPORT')->exists());
        $this->assertSame(2, $event->products()->where('code', 'NUMBER-REPORT')->firstOrFail()->quantity);
    }

    public function test_each_and_numeric_duplicate_lines_cannot_be_merged(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $project = Project::factory()->create();
        $line = $this->createLine($project, 'MIXED-QTY', 4, 15);
        $line->update(['qty_is_each' => true]);
        $numeric = $this->createLine($project, 'MIXED-QTY', 2, 15);
        $issue = collect(app(ProjectRevisionValidator::class)->issues($project->activeRevision))
            ->firstWhere('type', 'duplicate_sku');
        $this->assertFalse($issue['mergeable']);
        Livewire::test(ValidationProject::class, ['record' => $project->id])->call('mergeIssue', $issue['key']);
        $this->assertNotNull($line->fresh());
        $this->assertNotNull($numeric->fresh());
        $this->assertSame(2, $numeric->fresh()->qty);
    }

    public function test_each_respects_line_edit_permission_and_approved_revision_lock(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->for($admin)->create();
        $line = $this->createLine($project, 'LOCKED-EACH', 4, 15);
        $this->actingAs(User::factory()->sales()->create());
        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('updateLineField', $line->id, 'qty', 'E')->assertForbidden();
        $this->assertSame(4, $line->fresh()->qty);

        $this->actingAs($admin);
        $project->activeRevision->update(['status' => ProjectRevisionStatus::Approved]);
        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('updateLineField', $line->id, 'qty', 'E');
        $this->assertFalse($line->fresh()->qty_is_each);
        $this->assertSame(4, $line->fresh()->qty);
    }

    private function createLine(Project $project, string $code, int $qty, float $price): ProjectLine
    {
        $product = Product::query()->where('sku', $code)->first()
            ?? Product::factory()->create(['sku' => $code, 'price' => $price]);

        return $project->activeRevision->areas()->first()->lines()->create([
            'product_id' => $product->id,
            'code' => $code,
            'description' => 'Each quantity test product',
            'qty' => $qty,
            'unit_price' => $price,
            'type' => ProjectLineType::Standard,
            'sort_order' => 0,
        ]);
    }
}
