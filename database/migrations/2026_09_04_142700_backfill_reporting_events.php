<?php

use App\Services\ReportingEventService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('activity_logs') && Schema::hasTable('reporting_events')) {
            app(ReportingEventService::class)->syncMissing();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reporting rows are removed by the reporting table rollback.
    }
};
