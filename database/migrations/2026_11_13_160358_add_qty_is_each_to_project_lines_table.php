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
        Schema::table('project_lines', function (Blueprint $table) {
            $table->boolean('qty_is_each')->default(false)->after('qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_lines', function (Blueprint $table) {
            $table->dropColumn('qty_is_each');
        });
    }
};
