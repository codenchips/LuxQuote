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
        Schema::create('reporting_event_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporting_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->index();
            $table->string('description')->nullable();
            $table->unsignedInteger('quantity')->default(0);

            $table->unique(['reporting_event_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reporting_event_products');
    }
};
