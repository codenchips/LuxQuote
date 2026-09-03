<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Enums\ProjectStatus;
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
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $sales = User::factory()->sales()->create();
        $technical = User::factory()->technical()->create();
        $manager = User::factory()->manager()->create();

        $this->assertTrue($user->can('projects.create'));
        $this->assertTrue($user->can('projects.manage-tenders'));
        $this->assertTrue($user->can('projects.mark-design-complete'));
        $this->assertTrue($user->can('output.produce-unpriced-schedule'));
        $this->assertTrue($user->can('output.manage-document-packs'));
        $this->assertTrue($user->can('output.produce-document-packs'));
        $this->assertTrue($user->can('output.history.view'));
        $this->assertTrue($user->can('calendar.update'));
        $this->assertTrue($user->can('calendar.create'));
        $this->assertTrue($user->can('calendar.delete'));
        $this->assertTrue($admin->can('resources.view'));
        $this->assertTrue($admin->can('resources.create'));
        $this->assertTrue($admin->can('resources.update'));
        $this->assertTrue($admin->can('resources.delete'));
        $this->assertFalse($user->can('resources.view'));
        $this->assertFalse($user->can('resources.create'));
        $this->assertFalse($user->can('resources.update'));
        $this->assertFalse($user->can('resources.delete'));
        $this->assertFalse($user->can('pricing.view'));

        $this->assertTrue($sales->can('pricing.view'));
        $this->assertTrue($sales->can('output.produce-quote'));
        $this->assertTrue($sales->can('output.manage-document-packs'));
        $this->assertTrue($sales->can('output.history.view'));
        $this->assertFalse($sales->can('projects.create'));
        $this->assertFalse($sales->can('validation.update-lines'));
        $this->assertTrue($sales->can('calendar.update'));
        $this->assertTrue($sales->can('calendar.create'));
        $this->assertTrue($sales->can('calendar.delete'));
        $this->assertFalse($sales->can('resources.view'));
        $this->assertFalse($sales->can('resources.create'));
        $this->assertFalse($sales->can('resources.update'));
        $this->assertFalse($sales->can('resources.delete'));

        $this->assertTrue($technical->can('projects.update-lines'));
        $this->assertTrue($technical->can('projects.manage-tenders'));
        $this->assertTrue($technical->can('projects.mark-design-complete'));
        $this->assertTrue($technical->can('validation.merge-lines'));
        $this->assertFalse($technical->can('pricing.view'));
        $this->assertFalse($technical->can('output.produce-priced-schedule'));
        $this->assertTrue($technical->can('output.produce-document-packs'));
        $this->assertTrue($technical->can('output.history.view'));
        $this->assertTrue($technical->can('calendar.update'));
        $this->assertTrue($technical->can('calendar.create'));
        $this->assertTrue($technical->can('calendar.delete'));
        $this->assertFalse($technical->can('resources.view'));
        $this->assertFalse($technical->can('resources.create'));
        $this->assertFalse($technical->can('resources.update'));
        $this->assertFalse($technical->can('resources.delete'));

        $this->assertTrue($manager->can('projects.create'));
        $this->assertTrue($manager->can('projects.update-lines'));
        $this->assertTrue($manager->can('projects.manage-tenders'));
        $this->assertTrue($manager->can('projects.mark-design-complete'));
        $this->assertTrue($manager->can('revisions.approve'));
        $this->assertTrue($manager->can('pricing.view'));
        $this->assertTrue($manager->can('output.manage-document-packs'));
        $this->assertTrue($manager->can('output.history.view'));
        $this->assertTrue($manager->can('salesforce.view'));
        $this->assertTrue($manager->can('calendar.update'));
        $this->assertTrue($manager->can('calendar.create'));
        $this->assertTrue($manager->can('calendar.delete'));
        $this->assertFalse($manager->can('resources.view'));
        $this->assertFalse($manager->can('resources.create'));
        $this->assertFalse($manager->can('resources.update'));
        $this->assertFalse($manager->can('resources.delete'));
        $this->assertFalse($manager->can('salesforce.manage-push'));
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

    public function test_design_complete_has_its_own_permission(): void
    {
        $technical = User::factory()->technical()->create();
        $this->actingAs($technical);

        $project = Project::factory()->for($technical)->create([
            'status' => ProjectStatus::InProgress,
        ]);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('toggleDesignComplete');

        $this->assertSame(ProjectStatus::DesignComplete, $project->fresh()->status);

        $sales = User::factory()->sales()->create();
        $this->actingAs($sales);

        Livewire::test(ViewProject::class, ['record' => $project->id])
            ->call('toggleDesignComplete')
            ->assertForbidden();
    }
}
