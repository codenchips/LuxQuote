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
        Schema::table('salesforce_customer_defaults', function (Blueprint $table) {
            $table->string('cover_direction', 20)->default('added')->after('cover_3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salesforce_customer_defaults', function (Blueprint $table) {
            $table->dropColumn('cover_direction');
        });
    }
};
