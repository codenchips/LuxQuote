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
        Schema::create('project_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_area_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('description')->default('');
            $table->unsignedInteger('qty')->default(1);
            $table->string('type')->default('standard');
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_lines');
    }
};
