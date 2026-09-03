<?php

use App\Enums\PermissionKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $permissionKeys = [
        PermissionKey::ResourcesView->value,
        PermissionKey::ResourcesCreate->value,
        PermissionKey::ResourcesUpdate->value,
        PermissionKey::ResourcesDelete->value,
    ];

    public function up(): void
    {
        if (
            ! Schema::hasTable('permissions')
            || ! Schema::hasTable('permission_groups')
            || ! Schema::hasTable('permission_group_permission')
        ) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', $this->permissionKeys)
            ->pluck('id');
        $nonAdminGroupIds = DB::table('permission_groups')
            ->where('slug', '!=', 'admin')
            ->pluck('id');

        DB::table('permission_group_permission')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('permission_group_id', $nonAdminGroupIds)
            ->delete();
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('permissions')
            || ! Schema::hasTable('permission_groups')
            || ! Schema::hasTable('permission_group_permission')
        ) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', $this->permissionKeys)
            ->pluck('id');
        $previouslyGrantedGroupIds = DB::table('permission_groups')
            ->whereIn('slug', ['user', 'sales', 'technical', 'manager'])
            ->pluck('id');

        foreach ($previouslyGrantedGroupIds as $groupId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_group_permission')->insertOrIgnore([
                    'permission_group_id' => $groupId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }
};
