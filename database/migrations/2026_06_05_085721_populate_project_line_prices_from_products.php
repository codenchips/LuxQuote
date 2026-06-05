<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('project_lines')
            ->join('project_areas', 'project_lines.project_area_id', '=', 'project_areas.id')
            ->join('project_revisions', 'project_areas.project_revision_id', '=', 'project_revisions.id')
            ->join('products', 'project_lines.code', '=', 'products.sku')
            ->whereNull('project_lines.unit_price')
            ->whereNotNull('products.price')
            ->where('project_revisions.validated', false)
            ->update([
                'project_lines.unit_price' => DB::raw('products.price'),
                'project_lines.updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
