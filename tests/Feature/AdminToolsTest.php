<?php

namespace Tests\Feature;

use App\Filament\Pages\Tools;
use App\Jobs\GeneratePdf;
use App\Models\User;
use App\Services\DatabaseImport;
use App\Services\DatabaseImportLock;
use App\Services\ScheduledMaintenance;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AdminToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_are_only_accessible_to_administrators(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get(Tools::getUrl())->assertOk();

        $this->actingAs(User::factory()->manager()->create());
        $this->get(Tools::getUrl())->assertForbidden();
    }

    public function test_admin_can_schedule_and_cancel_maintenance(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $start = now()->addHour()->format('Y-m-d\TH:i');
        $end = now()->addHour()->addMinutes(15)->format('Y-m-d\TH:i');
        Livewire::test(Tools::class)->set('maintenanceStart', $start)->set('maintenanceEnd', $end)
            ->call('scheduleMaintenance')->assertHasNoErrors()->assertSet('maintenanceScheduled', true);
        $this->getJson(route('maintenance.status'))->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('active', false);
        Livewire::test(Tools::class)->call('cancelMaintenance')->assertSet('maintenanceScheduled', false);
        $this->assertFalse(app(ScheduledMaintenance::class)->active());
    }

    public function test_start_time_suggests_a_return_fifteen_minutes_later_and_past_times_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $start = now()->addHour()->startOfMinute();
        Livewire::test(Tools::class)
            ->set('maintenanceStart', $start->format('Y-m-d\TH:i'))
            ->assertSet('maintenanceEnd', $start->copy()->addMinutes(15)->format('Y-m-d\TH:i'))
            ->set('maintenanceStart', now()->subHour()->format('Y-m-d\TH:i'))
            ->call('scheduleMaintenance')->assertHasErrors(['maintenanceStart']);
    }

    public function test_deployment_start_and_clear_keep_prior_sessions_invalid(): void
    {
        $maintenance = app(ScheduledMaintenance::class);
        $maintenance->schedule(now()->addHour(), now()->addHour()->addMinutes(15));
        $this->artisan('app:scheduled-maintenance', ['action' => 'start'])->assertSuccessful();
        $this->assertTrue($maintenance->active());
        $cutoff = $maintenance->cutoff();
        $this->artisan('app:scheduled-maintenance', ['action' => 'clear'])->assertSuccessful();
        $this->assertFalse($maintenance->active());
        $this->assertSame($cutoff, $maintenance->cutoff());
    }

    public function test_deployment_without_a_schedule_does_not_enable_maintenance(): void
    {
        $this->artisan('app:scheduled-maintenance', ['action' => 'start'])->assertSuccessful();
        $this->artisan('app:scheduled-maintenance', ['action' => 'clear'])->assertSuccessful();
        $this->assertSame([], app(ScheduledMaintenance::class)->settings());
    }

    public function test_maintenance_signs_out_users_and_idle_sessions_remain_invalid_after_success(): void
    {
        $user = User::factory()->admin()->create();
        Auth::login($user);
        $maintenance = app(ScheduledMaintenance::class);
        $maintenance->schedule(now()->addMinute(), now()->addMinutes(16));
        $this->getJson(route('maintenance.status'))->assertJsonPath('active', false)->assertJsonPath('signed_out', false);
        $this->travel(2)->minutes();
        $this->getJson(route('maintenance.status'))->assertJsonPath('active', true)->assertJsonPath('signed_out', true);
        $this->assertGuest();
        $this->get(route('filament.admin.auth.login'))->assertRedirect(route('maintenance.notice'));
        $maintenance->clear();
        $this->assertFalse($maintenance->active());
        $this->actingAs($user);
        $this->getJson(route('maintenance.status'))->assertJsonPath('signed_out', true);
        $this->assertGuest();
        Auth::login($user);
        $this->getJson(route('maintenance.status'))->assertJsonPath('signed_out', false);
    }

    public function test_maintenance_expires_at_return_time_and_invalidates_idle_sessions(): void
    {
        $user = User::factory()->admin()->create();
        $maintenance = app(ScheduledMaintenance::class);
        $maintenance->schedule(now()->addMinute(), now()->addMinutes(16));
        $this->travel(2)->minutes();
        $this->get(route('filament.admin.auth.login'))->assertRedirect(route('maintenance.notice'));

        $this->travel(14)->minutes();
        $this->getJson(route('maintenance.status'))->assertJsonPath('enabled', false)->assertJsonPath('active', false);
        $this->assertFalse($maintenance->settings()['enabled']);
        $this->get(route('filament.admin.auth.login'))->assertOk();

        $this->actingAs($user);
        $this->getJson(route('maintenance.status'))->assertJsonPath('signed_out', true);
        $this->assertGuest();
        Auth::login($user);
        $this->getJson(route('maintenance.status'))->assertJsonPath('signed_out', false);
    }

    public function test_non_admin_cannot_schedule_maintenance(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $this->get(Tools::getUrl())->assertForbidden();
        $this->assertSame([], app(ScheduledMaintenance::class)->settings());
    }

    public function test_only_admin_can_download_a_database_export(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs(User::factory()->manager()->create());
        $this->get(route('admin.tools.database-export'))->assertForbidden();
        $this->actingAs($admin);
        $response = $this->get(route('admin.tools.database-export'))->assertOk();
        $response->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertStringContainsString('CREATE TABLE `users`', file_get_contents($path));
        unlink($path);
    }

    public function test_import_requires_admin_and_exact_database_confirmation(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager)->post(route('admin.tools.database-import'), [
            'dump' => UploadedFile::fake()->createWithContent('backup.sql', '-- test'),
            'confirmation' => 'RESTORE testing',
        ])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.tools.database-import'), [
            'dump' => UploadedFile::fake()->createWithContent('backup.sql', '-- test'),
            'confirmation' => 'RESTORE wrong',
        ])->assertSessionHasErrors('confirmation');
    }

    public function test_import_rejects_incomplete_dump_before_entering_maintenance(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        config(['queue.default' => 'database']);
        $this->post(route('admin.tools.database-import'), [
            'dump' => UploadedFile::fake()->createWithContent('backup.sql', 'DROP TABLE IF EXISTS `users`;'),
            'confirmation' => 'RESTORE '.DB::connection()->getDatabaseName(),
        ])->assertStatus(422)->assertSee('complete backup of the current database tables');
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_application_requests_are_blocked_while_database_import_holds_the_lock(): void
    {
        $guard = app(DatabaseImportLock::class);
        $locks = $guard->acquire(true);
        $this->assertNotNull($locks);
        try {
            $this->get(route('filament.admin.auth.login'))->assertStatus(503);
        } finally {
            $guard->release($locks);
        }
        $this->get(route('filament.admin.auth.login'))->assertOk();
    }

    public function test_admin_confirmed_import_calls_the_import_service(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->mock(DatabaseImport::class)->shouldReceive('import')->once()
            ->withArgs(fn (UploadedFile $file, int $userId, array $locks): bool => $file->getClientOriginalName() === 'backup.sql' && $userId === $admin->id && count($locks) === 2)
            ->andReturn('/private/backup.sql');
        $this->post(route('admin.tools.database-import'), [
            'dump' => UploadedFile::fake()->createWithContent('backup.sql', '-- trusted backup'),
            'confirmation' => 'RESTORE '.DB::connection()->getDatabaseName(),
        ])->assertOk()->assertSee('database was restored');
    }

    public function test_queued_work_holds_the_import_lock_and_is_released_when_finished(): void
    {
        $guard = app(DatabaseImportLock::class);
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        event(new JobProcessing('database', $job));
        $this->assertNull($guard->acquire(true));
        event(new JobProcessed('database', $job));
        $locks = $guard->acquire(true);
        $this->assertNotNull($locks);
        try {
            $blockedJob = Mockery::mock(Job::class);
            $blockedJob->shouldReceive('payload')->andReturn([]);
            $blockedJob->shouldReceive('release')->once()->with(60);
            $blockedJob->shouldReceive('delete')->once();
            event(new JobProcessing('database', $blockedJob));
        } finally {
            $guard->release($locks);
        }
    }

    public function test_database_worker_defers_a_job_without_executing_it_during_an_import(): void
    {
        Queue::connection('database')->pushOn('pdf', new GeneratePdf(0));
        $guard = app(DatabaseImportLock::class);
        $locks = $guard->acquire(true);
        $this->assertNotNull($locks);
        try {
            app('queue.worker')->runNextJob('database', 'pdf', new WorkerOptions(sleep: 0, maxTries: 3));
        } finally {
            $guard->release($locks);
        }
        $this->assertSame(1, DB::table('jobs')->where('queue', 'pdf')->count());
        $this->assertGreaterThan(time(), DB::table('jobs')->where('queue', 'pdf')->value('available_at'));
    }

    public function test_scheduled_tasks_hold_the_import_lock_while_running(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'app:prune-generated-pdfs'));
        $this->assertNotNull($event);
        $guard = app(DatabaseImportLock::class);
        $event->callBeforeCallbacks(app());
        try {
            $this->assertNull($guard->acquire(true));
        } finally {
            $event->callAfterCallbacks(app());
        }
        $locks = $guard->acquire(true);
        $this->assertNotNull($locks);
        $guard->release($locks);
    }
}
