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
        Schema::create('salesforce_customer_defaults', function (Blueprint $table) {
            $table->id();
            $table->string('salesforce_account_id', 18)->collation('ascii_bin')->unique();
            $table->decimal('cover_1', 6, 2)->default(5);
            $table->decimal('cover_2', 6, 2)->default(5);
            $table->decimal('cover_3', 6, 2)->default(5);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salesforce_customer_defaults');
    }
};
