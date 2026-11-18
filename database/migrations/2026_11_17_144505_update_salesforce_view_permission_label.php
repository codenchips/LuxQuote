<?php

use App\Enums\PermissionKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('key', PermissionKey::SalesforceView->value)->update([
            'name' => PermissionKey::SalesforceView->label(),
            'description' => PermissionKey::SalesforceView->description(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('key', PermissionKey::SalesforceView->value)->update([
            'name' => 'View Salesforce projects list page',
            'description' => 'View Salesforce projects list page',
            'updated_at' => now(),
        ]);
    }
};
