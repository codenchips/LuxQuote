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
        Schema::create('resource_files', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->string('file_disk')->default('local');
            $table->string('file_path')->unique();
            $table->string('original_filename');
            $table->string('mime_type', 150);
            $table->string('extension', 20);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_files');
    }
};
