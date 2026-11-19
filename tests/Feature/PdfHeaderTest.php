<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectSchedulePdfService;
use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PdfHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_and_schedule_render_the_cover_branding_and_left_aligned_details(): void
    {
        $this->travelTo(now()->setDateTime(2026, 11, 19, 10, 0));
        $admin = User::factory()->admin()->create(['name' => 'Alexander Lawlor']);
        $project = Project::factory()->for($admin)->create([
            'name' => 'Jewson - Haringey',
            'reference_number' => '20748',
            'customer_name' => 'Stark (Jewsons)',
            'date' => '2020-01-01',
            'salesforce_project' => true,
            'salesforce_id' => '006000000000001AAA',
        ]);
        $salesforce = $this->mock(SalesforceService::class);
        $salesforce->shouldReceive('getOpportunityOwner')->andReturn([
            'id' => '005000000000001AAA',
            'name' => 'Kris Price',
            'email' => 'kprice@tamlite.co.uk',
            'mobile_phone' => '+44 07773337195',
        ]);
        $salesforce->shouldReceive('getOpportunityBranch')->andReturn(null);
        $area = $project->activeRevision->areas()->firstOrFail();
        $area->update(['name' => 'Ground Floor']);
        $area->lines()->create([
            'code' => 'AST220NW', 'ref' => 'A1', 'description' => 'ASTRO 20W - 4000K',
            'qty' => 8, 'unit_price' => 27.32, 'type' => ProjectLineType::Standard, 'sort_order' => 0,
        ]);
        $directory = storage_path('app/private/design-cover-reference');
        File::ensureDirectoryExists($directory);
        $service = app(ProjectSchedulePdfService::class);

        foreach (['schedule' => 'Lighting Schedule', 'quote' => 'Project Quotation'] as $type => $title) {
            $builder = $service->builder($project, $project->activeRevision, $type);
            $html = $builder->getHtml();
            $this->assertStringContainsString('<div class="doc-title">'.$title.'</div>', $html);
            $this->assertStringContainsString('width: 115mm; height: 30mm;', $html);
            $this->assertStringContainsString('text-align: left;', $html);
            $this->assertSame(1, substr_count($html, 'class="pdf-brand-panel"'));
            $this->assertSame(1, substr_count($html, '+44 07773337195'));
            $this->assertStringNotContainsString('Park Farm Industrial Estate', $html);
            $path = $directory.'/'.$type.'-header.pdf';
            File::put($path, $service->contentFromBuilder($builder));
            $pages = new Process(['qpdf', '--show-npages', $path]);
            $pages->mustRun();
            $this->assertSame('1', trim($pages->getOutput()));
            $extract = new Process(['gs', '-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=txtwrite', '-sOutputFile=-', $path]);
            $extract->mustRun();
            $text = preg_replace('/\s+/', ' ', $extract->getOutput());
            foreach ([strtoupper($title), '20748', 'Kris Price', 'kprice@tamlite.co.uk', '+44 07773337195', 'design@tamlite.co.uk', 'Jewson - Haringey', 'ASTRO 20W - 4000K'] as $value) {
                $this->assertStringContainsString($value, $text);
            }
        }
        $cover = $service->quoteCoverBuilder($project, $project->activeRevision);
        $this->assertStringContainsString('class="pdf-brand-panel"', $cover->getHtml());
        $this->assertStringNotContainsString('Park Farm Industrial Estate', $cover->getHtml());
        $coverPath = $directory.'/quote-letter-header.pdf';
        File::put($coverPath, $service->contentFromBuilder($cover));
        $coverPages = new Process(['qpdf', '--show-npages', $coverPath]);
        $coverPages->mustRun();
        $this->assertSame('1', trim($coverPages->getOutput()));

    }
}
