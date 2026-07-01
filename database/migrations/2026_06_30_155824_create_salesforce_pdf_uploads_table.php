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
        Schema::create('salesforce_pdf_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_revision_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('fingerprint_hash', 64);
            $table->string('filename');
            $table->string('salesforce_content_version_id')->nullable();
            $table->string('salesforce_content_document_id')->nullable();
            $table->string('salesforce_url', 2048)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'project_revision_id', 'document_type'], 'sf_pdf_uploads_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salesforce_pdf_uploads');
    }
};
