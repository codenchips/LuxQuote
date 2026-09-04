<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityLogArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_permanently_deletes_only_logs_older_than_configured_retention(): void
    {
        Carbon::setTestNow('2026-09-04 09:00:00');
        config()->set('activity-log.retention_months', 3);

        $user = User::factory()->create([
            'email' => 'history@example.com',
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
        $oldLog->forceFill(['created_at' => now()->subMonthsNoOverflow(3)->subSecond()])->save();

        $recentLog = ActivityLog::create([
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $user->email,
            'project_name_snapshot' => null,
            'payload' => null,
        ]);
        $recentLog->forceFill(['created_at' => now()->subMonthsNoOverflow(3)])->save();

        $this->artisan('app:prune-activity-logs')
            ->assertSuccessful();

        $this->assertDatabaseMissing(ActivityLog::class, [
            'id' => $oldLog->id,
        ]);
        $this->assertDatabaseHas(ActivityLog::class, [
            'id' => $recentLog->id,
        ]);

        $this->assertDatabaseCount('activity_log_archives', 0);
    }

    public function test_prune_command_honours_retention_override(): void
    {
        Carbon::setTestNow('2026-09-04 09:00:00');

        $user = User::factory()->create();
        $log = ActivityLog::create([
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $user->email,
            'project_name_snapshot' => null,
            'payload' => null,
        ]);
        $log->forceFill(['created_at' => now()->subMonthsNoOverflow(2)])->save();

        $this->artisan('app:prune-activity-logs', ['--months' => 1])
            ->assertSuccessful();

        $this->assertDatabaseMissing(ActivityLog::class, ['id' => $log->id]);
    }

    public function test_invalid_retention_override_fails_without_deleting_history(): void
    {
        $user = User::factory()->create();
        $log = ActivityLog::create([
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $user->email,
            'project_name_snapshot' => null,
            'payload' => null,
        ]);

        $this->artisan('app:prune-activity-logs', ['--months' => 'invalid'])
            ->assertFailed();

        $this->assertDatabaseHas(ActivityLog::class, ['id' => $log->id]);
    }

    public function test_prune_command_dry_run_does_not_delete_or_restore_logs(): void
    {
        Carbon::setTestNow('2026-09-04 09:00:00');
        $user = User::factory()->create();
        $log = ActivityLog::create([
            'user_id' => $user->id,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => $user->email,
            'project_name_snapshot' => null,
            'payload' => null,
        ]);
        $log->forceFill(['created_at' => now()->subMonthsNoOverflow(4)])->save();

        $this->artisan('app:prune-activity-logs', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas(ActivityLog::class, ['id' => $log->id]);
    }

    public function test_prune_command_restores_retained_legacy_archives_and_discards_expired_archives(): void
    {
        Carbon::setTestNow('2026-09-04 09:00:00');

        DB::table('activity_log_archives')->insert([
            [
                'original_activity_log_id' => 100,
                'user_id' => 999999,
                'project_id' => 999999,
                'action_type' => 'user.login',
                'user_email_snapshot' => 'retained@example.com',
                'project_name_snapshot' => 'Deleted project',
                'revision_number' => null,
                'payload' => json_encode(['retained' => true], JSON_THROW_ON_ERROR),
                'created_at' => now()->subMonthsNoOverflow(2),
                'archived_at' => now()->subMonth(),
            ],
            [
                'original_activity_log_id' => 101,
                'user_id' => null,
                'project_id' => null,
                'action_type' => 'user.login',
                'user_email_snapshot' => 'expired@example.com',
                'project_name_snapshot' => null,
                'revision_number' => null,
                'payload' => null,
                'created_at' => now()->subMonthsNoOverflow(4),
                'archived_at' => now()->subMonth(),
            ],
        ]);

        $this->artisan('app:prune-activity-logs', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => null,
            'project_id' => null,
            'user_email_snapshot' => 'retained@example.com',
        ]);
        $this->assertDatabaseMissing('activity_logs', ['user_email_snapshot' => 'expired@example.com']);
        $this->assertDatabaseCount('activity_log_archives', 0);
    }

    public function test_quoted_status_no_longer_depends_on_retained_activity_history(): void
    {
        $project = Project::factory()->create();
        $revision = $project->activeRevision;

        $project->markQuoted($revision);

        $this->assertNotNull($revision->fresh()->quoted_at);
        $this->assertSame(ProjectStatus::Quoted, $project->fresh()->status);

        ActivityLog::query()->where('project_id', $project->id)->delete();
        $project->refresh()->syncStatusFromActiveRevision();

        $this->assertSame(ProjectStatus::Quoted, $project->fresh()->status);
    }

    public function test_global_history_query_never_displays_rows_outside_retention(): void
    {
        Carbon::setTestNow('2026-09-04 09:00:00');
        config()->set('activity-log.retention_months', 3);
        $admin = User::factory()->admin()->create();

        $expiredLog = ActivityLog::create([
            'user_id' => null,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => 'expired-history@example.com',
            'project_name_snapshot' => null,
            'payload' => null,
        ]);
        $expiredLog->forceFill(['created_at' => now()->subMonthsNoOverflow(3)->subSecond()])->save();

        ActivityLog::create([
            'user_id' => null,
            'project_id' => null,
            'action_type' => 'user.login',
            'user_email_snapshot' => 'retained-history@example.com',
            'project_name_snapshot' => null,
            'payload' => null,
        ]);

        $this->actingAs($admin);

        Livewire::test(ListActivityLogs::class)
            ->assertSuccessful()
            ->assertSee('retained-history@example.com')
            ->assertDontSee('expired-history@example.com');
    }

    public function test_scheduler_registers_activity_history_pruning(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('app:prune-activity-logs')
            ->assertSuccessful();
    }
}
