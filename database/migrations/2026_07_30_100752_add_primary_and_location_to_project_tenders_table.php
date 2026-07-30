<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('project_tenders')) {
            return;
        }

        Schema::table('project_tenders', function (Blueprint $table) {
            if (! Schema::hasColumn('project_tenders', 'billing_city')) {
                $table->string('billing_city')->nullable()->after('account_name');
            }

            if (! Schema::hasColumn('project_tenders', 'cef_region')) {
                $table->string('cef_region')->nullable()->after('billing_city');
            }

            if (! Schema::hasColumn('project_tenders', 'is_primary')) {
                $table->boolean('is_primary')->default(false)->after('cef_region');
            }
        });

        foreach (DB::table('project_tenders')->select('project_id')->distinct()->pluck('project_id') as $projectId) {
            $hasPrimary = DB::table('project_tenders')
                ->where('project_id', $projectId)
                ->where('is_primary', true)
                ->exists();

            if ($hasPrimary) {
                continue;
            }

            $firstTenderId = DB::table('project_tenders')
                ->where('project_id', $projectId)
                ->orderBy('id')
                ->value('id');

            if ($firstTenderId !== null) {
                DB::table('project_tenders')
                    ->where('id', $firstTenderId)
                    ->update(['is_primary' => true]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('project_tenders')) {
            return;
        }

        Schema::table('project_tenders', function (Blueprint $table) {
            if (Schema::hasColumn('project_tenders', 'is_primary')) {
                $table->dropColumn('is_primary');
            }

            if (Schema::hasColumn('project_tenders', 'cef_region')) {
                $table->dropColumn('cef_region');
            }

            if (Schema::hasColumn('project_tenders', 'billing_city')) {
                $table->dropColumn('billing_city');
            }
        });
    }
};
