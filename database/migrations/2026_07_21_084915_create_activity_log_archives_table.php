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
        if (Schema::hasTable('activity_log_archives')) {
            return;
        }

        Schema::create('activity_log_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_activity_log_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('action_type')->index();
            $table->string('user_email_snapshot');
            $table->string('project_name_snapshot')->nullable();
            $table->unsignedInteger('revision_number')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->index();
            $table->timestamp('archived_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log_archives');
    }
};
