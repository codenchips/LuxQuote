<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('special_order_codes')) {
            return;
        }

        Schema::table('special_order_codes', function (Blueprint $table): void {
            if (! Schema::hasColumn('special_order_codes', 'price')) {
                $table->decimal('price', 12, 2)->default(0)->after('description');
            }

            if (! Schema::hasColumn('special_order_codes', 'qty')) {
                $table->unsignedInteger('qty')->default(0)->after('price');
            }
        });

        DB::table('special_order_codes')
            ->where('normalised_code', 'NOOFFER')
            ->update([
                'price' => 0,
                'qty' => 0,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('special_order_codes')) {
            return;
        }

        Schema::table('special_order_codes', function (Blueprint $table): void {
            if (Schema::hasColumn('special_order_codes', 'qty')) {
                $table->dropColumn('qty');
            }

            if (Schema::hasColumn('special_order_codes', 'price')) {
                $table->dropColumn('price');
            }
        });
    }
};
