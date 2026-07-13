<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            if (! Schema::hasColumn('projects', 'has_cover')) {
                $table->boolean('has_cover')->default(false)->after('branch_name');
            }
        });

        DB::table('projects')
            ->where(function ($query): void {
                $query->whereNotNull('cover_1')
                    ->orWhereNotNull('cover_2')
                    ->orWhereNotNull('cover_3');
            })
            ->update(['has_cover' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            if (Schema::hasColumn('projects', 'has_cover')) {
                $table->dropColumn('has_cover');
            }
        });
    }
};
