<?php

use App\Enums\PermissionKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const Permission = PermissionKey::ProjectsDeletePermanently;

    public function up(): void
    {
        if (
            ! Schema::hasTable('permissions')
            || ! Schema::hasTable('permission_groups')
            || ! Schema::hasTable('permission_group_permission')
        ) {
            return;
        }

        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['key' => self::Permission->value],
            [
                'name' => self::Permission->label(),
                'category' => self::Permission->category(),
                'description' => self::Permission->description(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $permissionId = DB::table('permissions')->where('key', self::Permission->value)->value('id');
        $adminGroupId = DB::table('permission_groups')->where('slug', 'admin')->value('id');

        if ($permissionId !== null && $adminGroupId !== null) {
            DB::table('permission_group_permission')->insertOrIgnore([
                'permission_group_id' => $adminGroupId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('key', self::Permission->value)->value('id');

        if ($permissionId === null) {
            return;
        }

        if (Schema::hasTable('permission_group_permission')) {
            DB::table('permission_group_permission')->where('permission_id', $permissionId)->delete();
        }

        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
