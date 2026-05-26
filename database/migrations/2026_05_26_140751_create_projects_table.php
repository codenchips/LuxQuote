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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->unique();
            $table->string('reference_number')->unique()->nullable();
            $table->string('customer_name');
            $table->string('contractor')->nullable();
            $table->string('site_location')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('created_by_email')->nullable();
            $table->string('department')->nullable();
            $table->date('date')->nullable();
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('visibility')->default('open');
            $table->string('status')->default('draft');
            $table->string('branch_name')->nullable();
            $table->decimal('cover_percentage', 5, 2)->nullable();
            $table->text('quote_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('general_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
