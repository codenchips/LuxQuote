<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['project_id', 'revision_number']);
        });

        // Seed an initial revision record for every existing project
        DB::statement('
            INSERT INTO project_revisions (project_id, revision_number, created_by, created_at, updated_at)
            SELECT id, COALESCE(revision, 1), user_id, created_at, updated_at
            FROM projects
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_revisions');
    }
};
