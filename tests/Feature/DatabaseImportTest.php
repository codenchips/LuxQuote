<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\DatabaseImport;
use App\Services\DatabaseImportLock;
use App\Services\ScheduledMaintenance;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DatabaseImportTest extends TestCase
{
    use DatabaseMigrations;

    private string $originalStoragePath;

    private string $isolatedStoragePath;

    public function runDatabaseMigrations(): void
    {
        if (DB::connection()->getDatabaseName() !== 'testing') {
            throw new RuntimeException('Destructive import tests may only run on the testing database.');
        }
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->originalStoragePath = app()->storagePath();
        $this->isolatedStoragePath = sys_get_temp_dir().'/luxquote-import-test-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/framework');
        app()->useStoragePath($this->isolatedStoragePath);
        config(['app.maintenance.driver' => 'array', 'queue.default' => 'database']);
        app()->forgetInstance(MaintenanceModeManager::class);
    }

    protected function tearDown(): void
    {
        if (app()->isDownForMaintenance()) {
            Artisan::call('up');
        }
        app()->useStoragePath($this->originalStoragePath);
        File::deleteDirectory($this->isolatedStoragePath);
        parent::tearDown();
    }

    public function test_import_restores_local_testing_database_and_preserves_a_preimport_backup(): void
    {
        AppSetting::create(['key' => 'import-test', 'value' => ['value' => 'original']]);
        $dump = app(DatabaseBackup::class)->createDump();
        AppSetting::query()->where('key', 'import-test')->firstOrFail()->update(['value' => ['value' => 'changed']]);

        $backup = app(DatabaseImport::class)->import(new UploadedFile($dump, 'backup.sql', 'application/sql', null, true), 1);

        $this->assertSame(['value' => 'original'], AppSetting::query()->where('key', 'import-test')->value('value'));
        $this->assertFileExists($backup);
        $this->assertStringContainsString('changed', file_get_contents($backup));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertNotNull(app(ScheduledMaintenance::class)->cutoff());
    }

    public function test_admin_can_import_a_complete_dump_through_the_tools_form(): void
    {
        $admin = User::factory()->admin()->create();
        AppSetting::create(['key' => 'form-import-test', 'value' => ['value' => 'original']]);
        $dump = app(DatabaseBackup::class)->createDump();
        AppSetting::query()->where('key', 'form-import-test')->firstOrFail()->update(['value' => ['value' => 'changed']]);

        $this->actingAs($admin)->post(route('admin.tools.database-import'), [
            'dump' => new UploadedFile($dump, 'restore.sql', 'application/sql', null, true),
            'confirmation' => 'RESTORE testing',
        ])->assertOk()->assertSee('Database import complete');

        $this->assertSame(['value' => 'original'], AppSetting::query()->where('key', 'form-import-test')->value('value'));
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_failed_import_keeps_maintenance_active_and_preserves_the_backup(): void
    {
        $dump = app(DatabaseBackup::class)->createDump();
        file_put_contents($dump, "\nTHIS IS NOT VALID SQL;\n", FILE_APPEND);
        $failure = null;
        try {
            app(DatabaseImport::class)->import(new UploadedFile($dump, 'backup.sql', 'application/sql', null, true), 1);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        $this->assertNotNull($failure, 'The invalid SQL must fail.');
        $this->assertStringContainsString('remains in maintenance mode', $failure->getMessage());
        $this->assertTrue(app()->isDownForMaintenance());
        $this->assertCount(2, glob($this->isolatedStoragePath.'/app/private/database-exports/luxquote-*'));
    }

    public function test_import_refuses_to_start_while_a_queued_job_is_running(): void
    {
        $dump = app(DatabaseBackup::class)->createDump();
        DB::table('jobs')->insert([
            'queue' => 'pdf', 'payload' => '{}', 'attempts' => 1,
            'reserved_at' => time(), 'available_at' => time(), 'created_at' => time(),
        ]);
        try {
            app(DatabaseImport::class)->import(new UploadedFile($dump, 'backup.sql', 'application/sql', null, true), 1);
            $this->fail('The running job must block the restore.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('queued job is still running', $exception->getMessage());
        }
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertCount(1, glob($this->isolatedStoragePath.'/app/private/database-exports/luxquote-*'));
    }

    public function test_import_refuses_to_start_while_an_application_request_is_running(): void
    {
        $dump = app(DatabaseBackup::class)->createDump();
        $guard = app(DatabaseImportLock::class);
        $locks = $guard->acquire(false);
        $this->assertNotNull($locks);
        try {
            app(DatabaseImport::class)->import(new UploadedFile($dump, 'backup.sql', 'application/sql', null, true), 1);
            $this->fail('The active request must block the restore.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('database is busy', $exception->getMessage());
        } finally {
            $guard->release($locks);
        }
        $this->assertFalse(app()->isDownForMaintenance());
    }
}
