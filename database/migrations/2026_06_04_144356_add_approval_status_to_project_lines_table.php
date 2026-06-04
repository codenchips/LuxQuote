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
            $table->boolean('approved')->default(false)->after('status');
            $table->timestamp('approved_at')->nullable()->after('approved');
            $table->foreignId('approved_by')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_lines', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved', 'approved_at', 'approved_by']);
        });
    }
};
