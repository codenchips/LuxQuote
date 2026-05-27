<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_areas', function (Blueprint $table): void {
            $table->foreignId('project_revision_id')->nullable()->after('project_id')->constrained()->cascadeOnDelete();
        });

        // Associate every existing area with its project's initial revision
        DB::statement('
            UPDATE project_areas pa
            INNER JOIN project_revisions pr ON pr.project_id = pa.project_id
            SET pa.project_revision_id = pr.id
        ');

        Schema::table('project_areas', function (Blueprint $table): void {
            $table->foreignId('project_revision_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_areas', function (Blueprint $table): void {
            $table->dropForeign(['project_revision_id']);
            $table->dropColumn('project_revision_id');
        });
    }
};
