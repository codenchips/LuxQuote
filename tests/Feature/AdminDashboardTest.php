<?php

namespace Tests\Feature;

use App\Enums\DocumentPackItemRole;
use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Filament\Pages\Dashboard;
use App\Models\ActivityLog;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_recent_projects_owned_projects_and_generated_outputs(): void
    {
        $this->travelTo('2026-06-29 10:30:00');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $recentProject = Project::factory()->create([
            'name' => 'Recent Interaction Project',
            'status' => ProjectStatus::InProgress,
            'visibility' => ProjectVisibility::Private,
            'last_edited_at' => now()->subMinutes(30),
        ]);
        $this->activityLog($admin, $recentProject, 'project.updated', now()->subMinutes(5));

        $ownProject = Project::factory()->for($admin, 'user')->create([
            'name' => 'Own Latest Project',
            'last_edited_at' => now()->subMinutes(20),
        ]);

        $scheduleProject = Project::factory()->create(['name' => 'Schedule Output Project']);
        $scheduleRevision = $scheduleProject->activeRevision;
        $this->activityLog($admin, $scheduleProject, 'schedule_pdf.generated', now()->subMinutes(4), $scheduleRevision, [
            'filename' => 'schedule-output.pdf',
        ]);

        $quoteProject = Project::factory()->create(['name' => 'Quote Output Project']);
        $quoteRevision = $quoteProject->activeRevision;
        $this->activityLog($admin, $quoteProject, 'quote_pdf.generated', now()->subMinutes(3), $quoteRevision, [
            'filename' => 'quote-output.pdf',
        ]);

        $packProject = Project::factory()->create(['name' => 'Pack Output Project']);
        $packRevision = $packProject->activeRevision;
        $documentPack = DocumentPack::factory()->for($packProject)->create(['name' => 'Tender Pack']);
        $this->activityLog($admin, $packProject, 'document_pack.generated', now()->subMinutes(2), $packRevision, [
            'document_pack_id' => $documentPack->id,
            'document_pack_name' => $documentPack->name,
            'filename' => 'tender-pack.pdf',
        ]);

        Livewire::test(Dashboard::class)
            ->assertSee('Welcome, '.$admin->name)
            ->assertSee('Recent Projects')
            ->assertSee('Recent Interaction Project')
            ->assertSee('In Progress')
            ->assertSee('Private')
            ->assertSee('Your Projects')
            ->assertSee('Own Latest Project')
            ->assertSee('Jun 29 2026 10:10')
            ->assertSee('Recent Schedules')
            ->assertSee('Schedule Output Project')
            ->assertSee(route('projects.pdf.schedule', [
                'project' => $scheduleProject,
                'revision' => $scheduleRevision->id,
            ]), false)
            ->assertSee('Recent Quotes')
            ->assertSee('Quote Output Project')
            ->assertSee(route('projects.pdf.quote', [
                'project' => $quoteProject,
                'revision' => $quoteRevision->id,
            ]), false)
            ->assertSee('Recent Document Packs')
            ->assertSee('Document pack')
            ->assertSee('Tender Pack')
            ->assertSee('Pack Output Project')
            ->assertSee(route('projects.document-packs.download', [
                'project' => $packProject,
                'documentPack' => $documentPack,
                'revision' => $packRevision->id,
            ]), false)
            ->assertSee('User')
            ->assertSee($admin->name)
            ->assertSee('Projects created')
            ->assertSee('Edit profile');

        $this->assertSame('Own Latest Project', $ownProject->fresh()->name);
    }

    public function test_dashboard_hides_quote_rows_from_users_without_pricing_permission(): void
    {
        $admin = User::factory()->admin()->create();
        $quoteProject = Project::factory()->for($admin, 'user')->create([
            'name' => 'Restricted Quote Output',
            'visibility' => ProjectVisibility::Private,
        ]);
        $this->activityLog($admin, $quoteProject, 'quote_pdf.generated', now(), $quoteProject->activeRevision);

        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertDontSee('Recent Quotes')
            ->assertDontSee('No recent quotes.')
            ->assertDontSee('Restricted Quote Output');
    }

    public function test_dashboard_hides_archived_projects_from_all_project_tables(): void
    {
        $this->travelTo('2026-06-29 10:30:00');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $visibleProject = Project::factory()->for($admin, 'user')->create([
            'name' => 'Visible Dashboard Project',
        ]);
        $this->activityLog($admin, $visibleProject, 'project.updated', now()->subMinutes(6));

        $archivedRecentProject = Project::factory()->create([
            'name' => 'Archived Recent Project',
            'status' => ProjectStatus::Archived,
        ]);
        $this->activityLog($admin, $archivedRecentProject, 'project.updated', now()->subMinutes(5));

        Project::factory()->for($admin, 'user')->create([
            'name' => 'Archived Owned Project',
            'status' => ProjectStatus::Archived,
        ]);

        $archivedScheduleProject = Project::factory()->create([
            'name' => 'Archived Schedule Project',
            'status' => ProjectStatus::Archived,
        ]);
        $this->activityLog($admin, $archivedScheduleProject, 'schedule_pdf.generated', now()->subMinutes(4), $archivedScheduleProject->activeRevision, [
            'filename' => 'archived-schedule.pdf',
        ]);

        $archivedQuoteProject = Project::factory()->create([
            'name' => 'Archived Quote Project',
            'status' => ProjectStatus::Archived,
        ]);
        $this->activityLog($admin, $archivedQuoteProject, 'quote_pdf.generated', now()->subMinutes(3), $archivedQuoteProject->activeRevision, [
            'filename' => 'archived-quote.pdf',
        ]);

        $archivedPackProject = Project::factory()->create([
            'name' => 'Archived Pack Project',
            'status' => ProjectStatus::Archived,
        ]);
        $archivedPack = DocumentPack::factory()->for($archivedPackProject)->create(['name' => 'Archived Pack']);
        $this->activityLog($admin, $archivedPackProject, 'document_pack.generated', now()->subMinutes(2), $archivedPackProject->activeRevision, [
            'document_pack_id' => $archivedPack->id,
            'document_pack_name' => $archivedPack->name,
            'filename' => 'archived-pack.pdf',
        ]);

        Livewire::test(Dashboard::class)
            ->assertSee('Visible Dashboard Project')
            ->assertDontSee('Archived Recent Project')
            ->assertDontSee('Archived Owned Project')
            ->assertDontSee('Archived Schedule Project')
            ->assertDontSee('Archived Quote Project')
            ->assertDontSee('Archived Pack Project')
            ->assertDontSee('Archived Pack')
            ->assertDontSee('archived-schedule.pdf')
            ->assertDontSee('archived-quote.pdf')
            ->assertDontSee('archived-pack.pdf');
    }

    public function test_dashboard_hides_document_packs_with_quotes_from_users_without_pricing_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $visibleProject = Project::factory()->create(['name' => 'Visible Pack Project']);
        $visiblePack = DocumentPack::factory()->for($visibleProject)->create(['name' => 'Schedule Only Pack']);
        DocumentPackItem::factory()->for($visiblePack)->create();
        $this->activityLog(User::factory()->admin()->create(), $visibleProject, 'document_pack.generated', now(), $visibleProject->activeRevision, [
            'document_pack_id' => $visiblePack->id,
            'document_pack_name' => $visiblePack->name,
            'filename' => 'schedule-only.pdf',
        ]);

        $hiddenProject = Project::factory()->create(['name' => 'Hidden Priced Output Project']);
        $hiddenPack = DocumentPack::factory()->for($hiddenProject)->create(['name' => 'Quote Pack']);
        DocumentPackItem::factory()->for($hiddenPack)->create([
            'role' => DocumentPackItemRole::Quote,
        ]);
        $this->activityLog(User::factory()->admin()->create(), $hiddenProject, 'document_pack.generated', now()->subMinute(), $hiddenProject->activeRevision, [
            'document_pack_id' => $hiddenPack->id,
            'document_pack_name' => $hiddenPack->name,
            'filename' => 'quote-pack.pdf',
        ]);

        Livewire::test(Dashboard::class)
            ->assertSee('Recent Document Packs')
            ->assertSee('Schedule Only Pack')
            ->assertSee('Visible Pack Project')
            ->assertDontSee('Quote Pack')
            ->assertDontSee(route('projects.document-packs.download', [
                'project' => $hiddenProject,
                'documentPack' => $hiddenPack,
                'revision' => $hiddenProject->activeRevision->id,
            ]), false);
    }

    public function test_recent_projects_only_show_five_latest_unique_interactions(): void
    {
        $admin = User::factory()->admin()->create();

        for ($index = 1; $index <= 6; $index++) {
            $project = Project::factory()->create(['name' => "Interaction Project {$index}"]);

            $this->activityLog(
                $admin,
                $project,
                'project.updated',
                now()->subMinutes(10 - $index),
            );
        }

        $this->actingAs($admin);

        Livewire::test(Dashboard::class)
            ->assertSee('Interaction Project 6')
            ->assertSee('Interaction Project 2')
            ->assertDontSee('Interaction Project 1');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function activityLog(
        User $user,
        Project $project,
        string $actionType,
        mixed $createdAt,
        ?ProjectRevision $revision = null,
        array $payload = [],
    ): ActivityLog {
        $activityLog = new ActivityLog([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'action_type' => $actionType,
            'user_email_snapshot' => $user->email,
            'project_name_snapshot' => $project->name,
            'revision_number' => $revision?->revision_number ?? $project->activeRevision?->revision_number,
            'payload' => $payload,
        ]);
        $activityLog->timestamps = false;
        $activityLog->created_at = $createdAt;
        $activityLog->save();

        return $activityLog;
    }
}
