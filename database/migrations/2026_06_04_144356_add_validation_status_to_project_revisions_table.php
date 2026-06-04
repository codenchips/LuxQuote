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
            $table->boolean('validated')->default(false)->after('created_by');
            $table->timestamp('validated_at')->nullable()->after('validated');
            $table->foreignId('validated_by')
                ->nullable()
                ->after('validated_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_revisions', function (Blueprint $table): void {
            $table->dropForeign(['validated_by']);
            $table->dropColumn(['validated', 'validated_at', 'validated_by']);
        });
    }
};
