<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityLogArchives\Pages\ListActivityLogArchives;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\ActivityLogArchive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityLogArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_command_moves_only_logs_older_than_retention_window(): void
    {
        Carbon::setTestNow('2026-07-21 09:00:00');

        $user = User::factory()->create([
            'email' => 'archiver@example.com',
        ]);
        $oldLog = ActivityLog::create([
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $user->email,
            'project_name_snapshot' => null,
            'payload' => [
                'login_context' => [
                    'display' => 'Chrome on Windows · #ABC123',
                ],
            ],
        ]);
        $oldLog->forceFill([
            'created_at' => now()->subWeeks(7),
        ])->save();

        $recentLog = ActivityLog::create([
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $user->email,
            'project_name_snapshot' => null,
            'payload' => null,
        ]);
        $recentLog->forceFill([
            'created_at' => now()->subWeeks(2),
        ])->save();

        $this->artisan('app:archive-activity-logs')
            ->assertSuccessful();

        $this->assertDatabaseMissing(ActivityLog::class, [
            'id' => $oldLog->id,
        ]);
        $this->assertDatabaseHas(ActivityLog::class, [
            'id' => $recentLog->id,
        ]);

        $archive = ActivityLogArchive::query()
            ->where('original_activity_log_id', $oldLog->id)
            ->firstOrFail();

        $this->assertSame('user.login', $archive->action_type);
        $this->assertSame('archiver@example.com', $archive->user_email_snapshot);
        $this->assertSame('Chrome on Windows · #ABC123', $archive->payload['login_context']['display']);
        $this->assertTrue($archive->created_at->equalTo(now()->subWeeks(7)));
        $this->assertTrue($archive->archived_at->equalTo(now()));
    }

    public function test_archive_command_dry_run_does_not_move_logs(): void
    {
        Carbon::setTestNow('2026-07-21 09:00:00');

        $user = User::factory()->create();
        $log = ActivityLog::create([
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $user->email,
            'project_name_snapshot' => null,
            'payload' => null,
        ]);
        $log->forceFill([
            'created_at' => now()->subWeeks(8),
        ])->save();

        $this->artisan('app:archive-activity-logs --dry-run')
            ->assertSuccessful();

        $this->assertSame(1, ActivityLog::query()->count());
        $this->assertSame(0, ActivityLogArchive::query()->count());
    }

    public function test_archived_logs_page_shows_and_searches_archived_entries(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Dean',
            'email' => 'dean@example.com',
        ]);
        $otherUser = User::factory()->create([
            'email' => 'other@example.com',
        ]);

        ActivityLogArchive::create([
            'original_activity_log_id' => 10,
            'user_id' => $admin->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $admin->email,
            'project_name_snapshot' => null,
            'revision_number' => null,
            'payload' => [
                'login_context' => [
                    'display' => 'Chrome on Windows · #ABC123',
                ],
            ],
            'created_at' => now()->subWeeks(9),
            'archived_at' => now(),
        ]);

        ActivityLogArchive::create([
            'original_activity_log_id' => 11,
            'user_id' => $otherUser->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $otherUser->email,
            'project_name_snapshot' => null,
            'revision_number' => null,
            'payload' => [
                'login_context' => [
                    'display' => 'Firefox on macOS · #DEF456',
                ],
            ],
            'created_at' => now()->subWeeks(10),
            'archived_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(ListActivityLogArchives::class)
            ->assertSuccessful()
            ->assertSee('Logged in')
            ->assertSee('Chrome on Windows · #ABC123')
            ->assertSee('Archived')
            ->searchTable('Chrome on Windows')
            ->assertSee('Chrome on Windows · #ABC123')
            ->assertDontSee('Firefox on macOS · #DEF456');
    }

    public function test_history_page_links_to_archived_logs(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(ListActivityLogs::class)
            ->assertSuccessful()
            ->assertActionVisible('viewArchivedLogs');
    }
}
