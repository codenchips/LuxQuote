<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            // Nullable initially so we can seed the value before constraining
            $table->foreignId('active_revision_id')->nullable()->after('revision')->constrained('project_revisions')->nullOnDelete();
        });

        // Point every project at its initial revision
        DB::statement('
            UPDATE projects p
            INNER JOIN project_revisions pr ON pr.project_id = p.id
            SET p.active_revision_id = pr.id
        ');
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['active_revision_id']);
            $table->dropColumn('active_revision_id');
        });
    }
};
