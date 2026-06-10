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
            $table->boolean('validation_flagged')->default(false)->after('approved_by');
            $table->string('validation_note')->nullable()->after('validation_flagged');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_lines', function (Blueprint $table) {
            $table->dropColumn(['validation_flagged', 'validation_note']);
        });
    }
};
