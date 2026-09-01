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
        Schema::table('permission_groups', function (Blueprint $table) {
            $table->string('default_landing_page')->default('dashboard')->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permission_groups', function (Blueprint $table) {
            $table->dropColumn('default_landing_page');
        });
    }
};
