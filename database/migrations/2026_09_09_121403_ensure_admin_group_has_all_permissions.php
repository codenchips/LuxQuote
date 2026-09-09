<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permission_groups')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('permission_group_permission')) {
            return;
        }

        $adminGroupId = DB::table('permission_groups')
            ->where('slug', 'admin')
            ->value('id');

        if ($adminGroupId === null) {
            return;
        }

        DB::table('permissions')
            ->pluck('id')
            ->each(fn (int $permissionId) => DB::table('permission_group_permission')->insertOrIgnore([
                'permission_group_id' => $adminGroupId,
                'permission_id' => $permissionId,
            ]));
    }

    /**
     * Admin permission assignments remain protected if this migration is rolled back.
     */
    public function down(): void {}
};
