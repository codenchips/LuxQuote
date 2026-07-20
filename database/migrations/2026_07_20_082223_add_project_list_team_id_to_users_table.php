<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'project_list_team_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('project_list_team_id')
                ->nullable()
                ->after('area_code')
                ->constrained('teams')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'project_list_team_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_list_team_id');
        });
    }
};
