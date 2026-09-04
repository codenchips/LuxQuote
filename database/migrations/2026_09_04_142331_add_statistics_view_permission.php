<?php

use App\Enums\PermissionKey;
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
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permission = PermissionKey::StatisticsView;
        DB::table('permissions')->updateOrInsert(
            ['key' => $permission->value],
            [
                'name' => $permission->label(),
                'category' => $permission->category(),
                'description' => $permission->description(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $permissionId = DB::table('permissions')->where('key', $permission->value)->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('permission_groups')->whereIn('slug', ['admin', 'manager'])->pluck('id')->each(
            fn (int $groupId) => DB::table('permission_group_permission')->insertOrIgnore([
                'permission_group_id' => $groupId,
                'permission_id' => $permissionId,
            ]),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('key', PermissionKey::StatisticsView->value)->value('id');

        if ($permissionId !== null) {
            DB::table('permission_group_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
