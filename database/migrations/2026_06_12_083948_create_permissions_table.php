<?php

use App\Enums\PermissionKey;
use App\Enums\UserRole;
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
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_group_permission', function (Blueprint $table) {
            $table->foreignId('permission_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_group_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('permission_group_id')
                ->nullable()
                ->after('role')
                ->constrained()
                ->nullOnDelete();
        });

        $now = now();

        foreach (PermissionKey::cases() as $permission) {
            DB::table('permissions')->insert([
                'key' => $permission->value,
                'name' => $permission->label(),
                'category' => $permission->category(),
                'description' => $permission->description(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (PermissionKey::defaultGroups() as $slug => $group) {
            $groupId = DB::table('permission_groups')->insertGetId([
                'name' => $group['label'],
                'slug' => $slug,
                'description' => $group['description'],
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($group['permissions'] as $permission) {
                $permissionId = DB::table('permissions')->where('key', $permission->value)->value('id');

                DB::table('permission_group_permission')->insert([
                    'permission_group_id' => $groupId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        $adminGroupId = DB::table('permission_groups')->where('slug', 'admin')->value('id');
        $userGroupId = DB::table('permission_groups')->where('slug', 'user')->value('id');

        DB::table('users')
            ->where('role', UserRole::Admin->value)
            ->update(['permission_group_id' => $adminGroupId]);

        DB::table('users')
            ->where('role', UserRole::Users->value)
            ->update(['permission_group_id' => $userGroupId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('permission_group_id');
        });

        Schema::dropIfExists('permission_group_permission');
        Schema::dropIfExists('permissions');
    }
};
