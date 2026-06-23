<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPermissionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_groups_have_expected_permissions(): void
    {
        $user = User::factory()->create();
        $sales = User::factory()->sales()->create();
        $technical = User::factory()->technical()->create();
        $manager = User::factory()->manager()->create();

        $this->assertTrue($user->can('projects.create'));
        $this->assertTrue($user->can('output.produce-unpriced-schedule'));
        $this->assertTrue($user->can('output.manage-document-packs'));
        $this->assertTrue($user->can('output.produce-document-packs'));
        $this->assertFalse($user->can('pricing.view'));

        $this->assertTrue($sales->can('pricing.view'));
        $this->assertTrue($sales->can('output.produce-quote'));
        $this->assertTrue($sales->can('output.manage-document-packs'));
        $this->assertFalse($sales->can('projects.create'));
        $this->assertFalse($sales->can('validation.update-lines'));

        $this->assertTrue($technical->can('projects.update-lines'));
        $this->assertTrue($technical->can('validation.merge-lines'));
        $this->assertFalse($technical->can('pricing.view'));
        $this->assertFalse($technical->can('output.produce-priced-schedule'));
        $this->assertTrue($technical->can('output.produce-document-packs'));

        $this->assertTrue($manager->can('projects.create'));
        $this->assertTrue($manager->can('projects.update-lines'));
        $this->assertTrue($manager->can('revisions.approve'));
        $this->assertTrue($manager->can('pricing.view'));
        $this->assertTrue($manager->can('output.manage-document-packs'));
    }

    public function test_technical_user_cannot_update_line_price(): void
    {
        $technical = User::factory()->technical()->create();
        $this->actingAs($technical);

        $project = Project::factory()->for($technical)->create([
            'name' => 'Technical Project',
            'customer_name' => 'Example Customer',
        ]);

        $area = $project->activeRevision->areas()->firstOrFail();
        $line = $area->lines()->create([
            'code' => 'SKU-001',
            'description' => 'Existing product',
            'qty' => 1,
            'type' => ProjectLineType::Standard->value,
            'unit_price' => 10,
            'sort_order' => 0,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('updateLineField', $line->id, 'unit_price', '99.00')
            ->assertForbidden();

        $this->assertSame('10.00', $line->fresh()->unit_price);
    }
}
