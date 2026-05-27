<?php

use App\Models\Product;
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
            $table->foreignId('product_id')
                ->nullable()
                ->after('project_area_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_lines', function (Blueprint $table): void {
            $table->dropForeignIdFor(Product::class);
        });
    }
};
