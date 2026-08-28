<?php

use App\Enums\PermissionKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, PermissionKey> */
    private array $permissions = [
        PermissionKey::CalendarDelete,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach ($this->permissions as $permission) {
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

            if ($permissionId === null) {
                continue;
            }

            foreach (PermissionKey::defaultGroups() as $slug => $group) {
                if (! in_array($permission, $group['permissions'], true)) {
                    continue;
                }

                $groupId = DB::table('permission_groups')->where('slug', $slug)->value('id');

                if ($groupId !== null) {
                    DB::table('permission_group_permission')->insertOrIgnore([
                        'permission_group_id' => $groupId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_map(fn (PermissionKey $permission): string => $permission->value, $this->permissions))
            ->pluck('id');

        DB::table('permission_group_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
