<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('owner_email')->index();
        });

        DB::table('users')->whereNotNull('email')->whereNotNull('name')->orderBy('id')->each(function (object $user): void {
            DB::table('projects')->whereNull('owner_name')->where('owner_email', $user->email)->update([
                'owner_name' => $user->name,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('owner_name');
        });
    }
};
