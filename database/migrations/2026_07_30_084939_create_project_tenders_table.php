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
        if (Schema::hasTable('project_tenders')) {
            return;
        }

        Schema::create('project_tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('salesforce_account_id');
            $table->string('account_name');
            $table->string('billing_city')->nullable();
            $table->string('cef_region')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('salesforce_tender_id')->nullable();
            $table->json('account_payload')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'salesforce_account_id']);
            $table->index('salesforce_tender_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_tenders');
    }
};
