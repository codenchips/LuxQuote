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
        Schema::create('reporting_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_log_id')->nullable()->unique();
            $table->string('event_type', 40)->index();
            $table->string('generation_batch_key', 80)->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name_snapshot')->nullable();
            $table->string('user_email_snapshot');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_reference_snapshot')->nullable()->index();
            $table->string('project_name_snapshot')->nullable();
            $table->string('owner_name_snapshot')->nullable()->index();
            $table->string('owner_email_snapshot')->nullable()->index();
            $table->unsignedInteger('revision_number')->nullable();
            $table->string('currency', 3)->nullable()->index();
            $table->decimal('net_value', 15, 2)->nullable();
            $table->decimal('gross_value', 15, 2)->nullable();
            $table->boolean('has_cover')->nullable();
            $table->decimal('effective_cover_percentage', 8, 3)->nullable();
            $table->boolean('include_datasheets')->nullable();
            $table->boolean('include_cover_letter')->nullable();
            $table->boolean('include_legal_page')->nullable();
            $table->unsignedInteger('tender_count')->nullable();
            $table->unsignedInteger('document_count')->nullable();
            $table->json('metadata')->nullable();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['project_id', 'event_type', 'occurred_at'], 'reporting_project_event_date_idx');
            $table->index(['user_id', 'event_type', 'occurred_at'], 'reporting_user_event_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reporting_events');
    }
};
