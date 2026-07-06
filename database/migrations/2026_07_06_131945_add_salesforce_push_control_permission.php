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

        $permission = PermissionKey::SalesforceManagePush;
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['key' => $permission->value],
            [
                'name' => $permission->label(),
                'category' => $permission->category(),
                'description' => $permission->description(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $permissionId = DB::table('permissions')->where('key', $permission->value)->value('id');
        $adminGroupId = DB::table('permission_groups')->where('slug', 'admin')->value('id');

        if ($permissionId !== null && $adminGroupId !== null) {
            DB::table('permission_group_permission')->insertOrIgnore([
                'permission_group_id' => $adminGroupId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('key', PermissionKey::SalesforceManagePush->value)
            ->value('id');

        if ($permissionId !== null) {
            DB::table('permission_group_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
