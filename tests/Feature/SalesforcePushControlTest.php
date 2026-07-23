<?php

namespace Tests\Feature;

use App\Enums\ProjectLineType;
use App\Filament\Pages\Salesforce;
use App\Filament\Resources\Projects\Pages\ValidationProject;
use App\Models\AppSetting;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Services\SalesforcePushControl;
use App\Services\SalesforceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SalesforcePushControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_pause_and_resume_salesforce_pushes(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Http::fake();

        Livewire::test(Salesforce::class)
            ->assertSet('salesforcePushDisabled', false)
            ->call('toggleSalesforcePushDisabled')
            ->assertSet('salesforcePushDisabled', true);

        $this->assertTrue(app(SalesforcePushControl::class)->disabled());
        $this->assertSame(['disabled' => true], AppSetting::where('key', 'salesforce_push_disabled')->value('value'));

        Livewire::test(Salesforce::class)
            ->assertSet('salesforcePushDisabled', true)
            ->call('toggleSalesforcePushDisabled')
            ->assertSet('salesforcePushDisabled', false);

        $this->assertFalse(app(SalesforcePushControl::class)->disabled());
        $this->assertSame(['disabled' => false], AppSetting::where('key', 'salesforce_push_disabled')->value('value'));
    }

    public function test_salesforce_push_pause_survives_a_fresh_page_mount(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Http::fake();

        Livewire::test(Salesforce::class)
            ->call('toggleSalesforcePushDisabled')
            ->assertSet('salesforcePushDisabled', true);

        auth()->logout();
        $this->actingAs($admin->fresh());

        Livewire::test(Salesforce::class)
            ->assertSet('salesforcePushDisabled', true);
    }

    public function test_salesforce_view_user_without_push_permission_cannot_toggle_pushes(): void
    {
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager);

        $this->assertTrue($manager->can('salesforce.view'));
        $this->assertFalse($manager->can('salesforce.manage-push'));

        Http::fake();

        Livewire::test(Salesforce::class)
            ->assertSet('salesforcePushDisabled', false)
            ->call('toggleSalesforcePushDisabled')
            ->assertForbidden();

        $this->assertFalse(app(SalesforcePushControl::class)->disabled());
    }

    public function test_disabled_salesforce_pushes_prevent_direct_pdf_and_amount_writes(): void
    {
        app(SalesforcePushControl::class)->setDisabled(true);

        $project = Project::factory()->create([
            'salesforce_project' => true,
            'salesforce_id' => '006000000000001AAA',
        ]);

        Http::fake();

        $uploadResult = app(SalesforceService::class)->uploadPdf(
            project: $project,
            pdfContent: '%PDF-1.4 test',
            filename: 'schedule.pdf',
        );

        $amountResult = app(SalesforceService::class)->updateOpportunityAmount($project, 123.45);

        $this->assertFalse($uploadResult['success']);
        $this->assertSame('Salesforce pushes are currently paused.', $uploadResult['message']);
        $this->assertFalse($amountResult['success']);
        $this->assertSame('Salesforce pushes are currently paused.', $amountResult['message']);
        Http::assertNothingSent();
    }

    public function test_disabled_salesforce_pushes_skip_amount_sync_on_revision_approval(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        app(SalesforcePushControl::class)->setDisabled(true);

        $project = Project::factory()->for($admin)->create([
            'salesforce_project' => true,
            'salesforce_id' => '006000000000001AAA',
        ]);
        $product = Product::factory()->create([
            'sku' => 'SYNC-SKIP',
            'price' => 12.34,
        ]);
        $project->activeRevision->areas()->first()->lines()->create([
            'code' => $product->sku,
            'description' => 'Sync skip line',
            'qty' => 2,
            'type' => ProjectLineType::Standard->value,
            'unit_price' => 12.34,
            'sort_order' => 0,
        ]);

        Http::fake();

        Livewire::test(ValidationProject::class, ['record' => $project->id])
            ->call('runValidation')
            ->call('approveRevision')
            ->assertNotified('Salesforce value update skipped');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/sobjects/Opportunity/'));
    }
}
