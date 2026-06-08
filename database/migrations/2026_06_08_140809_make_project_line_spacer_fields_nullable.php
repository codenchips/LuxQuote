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
        Schema::table('project_lines', function (Blueprint $table): void {
            $table->string('description')->nullable()->default(null)->change();
            $table->unsignedInteger('qty')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_lines', function (Blueprint $table): void {
            $table->string('description')->default('')->nullable(false)->change();
            $table->unsignedInteger('qty')->default(1)->nullable(false)->change();
        });
    }
};
