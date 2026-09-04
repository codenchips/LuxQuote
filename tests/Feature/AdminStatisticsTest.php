<?php

namespace Tests\Feature;

use App\Filament\Pages\Statistics;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Project;
use App\Models\ReportingEvent;
use App\Models\User;
use App\Services\ProjectOwnerNameResolver;
use App\Services\SalesforceService;
use App\Services\StatisticsReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_statistics_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Statistics::class)
            ->assertOk()
            ->assertSee('Project status funnel')
            ->assertSee('Median to first quote')
            ->assertSee('Activity over time')
            ->assertSee('Output mix')
            ->assertSee('Top output producers')
            ->assertDontSee('This year');
    }

    public function test_user_without_statistics_permission_cannot_view_page(): void
    {
        $group = PermissionGroup::query()->create([
            'name' => 'Restricted',
            'slug' => 'restricted-stats',
            'default_landing_page' => 'dashboard',
        ]);
        $this->actingAs(User::factory()->create(['permission_group_id' => $group->id]));

        Livewire::test(Statistics::class)->assertForbidden();
    }

    public function test_statistics_permission_is_available_and_granted_to_manager(): void
    {
        $permission = Permission::query()->where('key', 'statistics.view')->firstOrFail();
        $manager = PermissionGroup::query()->where('slug', 'manager')->firstOrFail();

        $this->assertTrue($manager->permissions()->whereKey($permission->id)->exists());
    }

    public function test_activity_log_is_copied_to_durable_reporting_store(): void
    {
        $user = User::factory()->create();

        $log = ActivityLog::query()->create([
            'user_id' => $user->id,
            'action_type' => 'user.login',
            'user_email_snapshot' => $user->email,
        ]);

        $this->assertDatabaseHas('reporting_events', [
            'activity_log_id' => $log->id,
            'event_type' => 'login',
            'user_id' => $user->id,
        ]);
    }

    public function test_quote_batches_are_not_double_counted_in_financial_totals(): void
    {
        $user = User::factory()->create();
        ReportingEvent::factory()->create([
            'event_type' => 'quote',
            'generation_batch_key' => 'quote-batch-one',
            'user_id' => $user->id,
            'currency' => 'GBP',
            'net_value' => 800,
            'gross_value' => 1000,
        ]);
        ReportingEvent::factory()->create([
            'event_type' => 'quote',
            'generation_batch_key' => 'quote-batch-one',
            'user_id' => $user->id,
            'currency' => 'GBP',
            'net_value' => 800,
            'gross_value' => 1000,
        ]);
        ReportingEvent::factory()->create([
            'event_type' => 'quote',
            'generation_batch_key' => 'quote-batch-two',
            'user_id' => $user->id,
            'currency' => 'EUR',
            'net_value' => 450,
            'gross_value' => 500,
        ]);

        $report = app(StatisticsReportService::class)->report(
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now()->addDay(),
            includeFinancials: true,
        );

        $this->assertSame(3, $report['summary']['quotes']);
        $this->assertSame(2, $report['summary']['quote_batches']);
        $this->assertSame(1000.0, $report['financials']['GBP']['gross']);
        $this->assertSame(500.0, $report['financials']['EUR']['gross']);
        $this->assertSame(20.0, $report['financials']['GBP']['cover']);
    }

    public function test_project_owner_name_is_used_instead_of_owner_email(): void
    {
        $project = Project::factory()->create([
            'owner_name' => 'Jane Manager',
            'owner_email' => 'jane.manager@example.com',
            'created_at' => now(),
        ]);

        $report = app(StatisticsReportService::class)->report(
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now()->addDay(),
        );

        $row = $report['project_rows']->firstWhere('reference', $project->reference_number);
        $this->assertSame('Jane Manager', $row['owner']);
        $this->assertStringNotContainsString('@', $row['owner']);
    }

    public function test_salesforce_owner_name_is_cached_locally_for_existing_project(): void
    {
        $project = Project::factory()->create([
            'owner_name' => null,
            'owner_email' => 'sales.owner@example.com',
            'salesforce_id' => '006000000000001AAA',
        ]);
        $salesforce = $this->mock(SalesforceService::class);
        $salesforce->shouldReceive('getOpportunityOwner')->once()->andReturn([
            'id' => '005000000000001AAA',
            'name' => 'Sales Owner',
            'email' => 'sales.owner@example.com',
        ]);

        $name = app(ProjectOwnerNameResolver::class)->resolve($project);

        $this->assertSame('Sales Owner', $name);
        $this->assertSame('Sales Owner', $project->fresh()->owner_name);
    }
}
