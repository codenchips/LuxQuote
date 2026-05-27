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
        // Migrate legacy 'temp' rows to 'custom' (closest semantic equivalent)
        DB::table('project_lines')->where('type', 'temp')->update(['type' => 'custom']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('project_lines')->where('type', 'custom')->update(['type' => 'temp']);
    }
};
