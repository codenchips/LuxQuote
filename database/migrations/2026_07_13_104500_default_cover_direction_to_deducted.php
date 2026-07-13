<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'cover_direction')) {
            return;
        }

        DB::table('projects')
            ->where('cover_direction', 'added')
            ->update(['cover_direction' => 'deducted']);

        DB::statement("ALTER TABLE projects MODIFY cover_direction VARCHAR(20) NOT NULL DEFAULT 'deducted'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'cover_direction')) {
            return;
        }

        DB::statement("ALTER TABLE projects MODIFY cover_direction VARCHAR(20) NOT NULL DEFAULT 'added'");
    }
};
