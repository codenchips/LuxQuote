<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_order_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('normalised_code')->unique();
            $table->text('description');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('qty')->default(0);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('show_on_schedules')->default(true);
            $table->boolean('show_on_quotes')->default(true);
            $table->timestamps();
        });

        DB::table('special_order_codes')->insert([
            'code' => 'NO OFFER',
            'normalised_code' => 'NOOFFER',
            'description' => 'No equivalent Tamlite offering available.',
            'price' => 0,
            'qty' => 0,
            'requires_approval' => false,
            'show_on_schedules' => true,
            'show_on_quotes' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('special_order_codes');
    }
};
