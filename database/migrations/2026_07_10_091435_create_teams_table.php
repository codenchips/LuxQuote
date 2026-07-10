<?php

use App\Enums\PermissionKey;
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
        if (! Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('team_user')) {
            Schema::create('team_user', function (Blueprint $table) {
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->primary(['team_id', 'user_id']);
            });
        }

        if (! Schema::hasColumn('projects', 'team_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreignId('team_id')
                    ->nullable()
                    ->after('visibility')
                    ->constrained()
                    ->nullOnDelete();
            });
        }

        $now = now();
        $permissionId = DB::table('permissions')
            ->where('key', PermissionKey::TeamsManage->value)
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'key' => PermissionKey::TeamsManage->value,
                'name' => PermissionKey::TeamsManage->label(),
                'category' => PermissionKey::TeamsManage->category(),
                'description' => PermissionKey::TeamsManage->description(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('permissions')
                ->where('id', $permissionId)
                ->update([
                    'name' => PermissionKey::TeamsManage->label(),
                    'category' => PermissionKey::TeamsManage->category(),
                    'description' => PermissionKey::TeamsManage->description(),
                    'updated_at' => $now,
                ]);
        }

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
        $permissionId = DB::table('permissions')
            ->where('key', PermissionKey::TeamsManage->value)
            ->value('id');

        if ($permissionId !== null) {
            DB::table('permission_group_permission')
                ->where('permission_id', $permissionId)
                ->delete();

            if (DB::table('permission_groups')->where('slug', 'admin')->value('id') !== null) {
                DB::table('permissions')
                    ->where('id', $permissionId)
                    ->delete();
            }
        }

        if (Schema::hasColumn('projects', 'team_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropConstrainedForeignId('team_id');
            });
        }

        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
