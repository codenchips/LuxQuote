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
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'cover_1')) {
                $table->decimal('cover_1', 6, 2)->nullable()->after('cover_percentage');
            }

            if (! Schema::hasColumn('projects', 'cover_2')) {
                $table->decimal('cover_2', 6, 2)->nullable()->after('cover_1');
            }

            if (! Schema::hasColumn('projects', 'cover_3')) {
                $table->decimal('cover_3', 6, 2)->nullable()->after('cover_2');
            }
        });

        Schema::table('project_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('project_lines', 'cover_1')) {
                $table->decimal('cover_1', 6, 2)->nullable()->after('unit_price');
            }

            if (! Schema::hasColumn('project_lines', 'cover_2')) {
                $table->decimal('cover_2', 6, 2)->nullable()->after('cover_1');
            }

            if (! Schema::hasColumn('project_lines', 'cover_3')) {
                $table->decimal('cover_3', 6, 2)->nullable()->after('cover_2');
            }
        });

        DB::table('projects')
            ->whereNull('cover_1')
            ->whereNotNull('cover_percentage')
            ->select(['id', 'cover_percentage'])
            ->orderBy('id')
            ->chunkById(100, function ($projects): void {
                foreach ($projects as $project) {
                    if (! is_numeric($project->cover_percentage)) {
                        continue;
                    }

                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update([
                            'cover_1' => min(999.99, max(0, (float) $project->cover_percentage)),
                        ]);
                }
            });

        $now = now();
        $permissionId = DB::table('permissions')
            ->where('key', PermissionKey::CoverUpdate->value)
            ->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'key' => PermissionKey::CoverUpdate->value,
                'name' => PermissionKey::CoverUpdate->label(),
                'category' => PermissionKey::CoverUpdate->category(),
                'description' => PermissionKey::CoverUpdate->description(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $groupIds = DB::table('permission_groups')
            ->whereIn('slug', ['admin', 'sales', 'manager'])
            ->pluck('id');

        foreach ($groupIds as $groupId) {
            DB::table('permission_group_permission')->insertOrIgnore([
                'permission_group_id' => $groupId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_lines', function (Blueprint $table) {
            foreach (['cover_1', 'cover_2', 'cover_3'] as $column) {
                if (Schema::hasColumn('project_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('projects', function (Blueprint $table) {
            foreach (['cover_1', 'cover_2', 'cover_3'] as $column) {
                if (Schema::hasColumn('projects', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        $permissionId = DB::table('permissions')
            ->where('key', PermissionKey::CoverUpdate->value)
            ->value('id');

        if ($permissionId !== null) {
            DB::table('permission_group_permission')
                ->where('permission_id', $permissionId)
                ->delete();

            DB::table('permissions')
                ->where('id', $permissionId)
                ->delete();
        }
    }
};
