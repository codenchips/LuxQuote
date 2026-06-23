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
        Schema::create('document_pack_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_pack_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50);
            $table->string('source_type', 30);
            $table->unsignedInteger('sort_order');
            $table->string('file_disk', 50)->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->index(['document_pack_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_pack_items');
    }
};
