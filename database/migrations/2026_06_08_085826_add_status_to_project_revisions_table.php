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
        Schema::table('project_revisions', function (Blueprint $table): void {
            $table->string('status')
                ->default('draft')
                ->after('validated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_revisions', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
