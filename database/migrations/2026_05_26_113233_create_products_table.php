<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('site')->nullable()->index();
            $table->string('product_name')->index();
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->string('type_name')->nullable()->index();
            $table->string('length_mm')->nullable();
            $table->string('width_mm')->nullable();
            $table->string('depth_mm')->nullable();
            $table->string('diameter_mm')->nullable();
            $table->string('cut_out_mm')->nullable();
            $table->string('weight_kg')->nullable();
            $table->string('luminaire_wattage_w')->nullable();
            $table->string('lumens_lm')->nullable();
            $table->string('efficacy_llm_w')->nullable();
            $table->string('beam_angle_fwhm')->nullable();
            $table->string('emergency_lumen_output')->nullable();
            $table->string('power')->nullable();
            $table->string('em_power')->nullable();
            $table->string('cct_k')->nullable();
            $table->string('colour_temp')->nullable();
            $table->string('cri')->nullable();
            $table->string('dali')->nullable();
            $table->string('vision_type')->nullable();
            $table->string('emergency_type')->nullable();
            $table->string('ip_rating')->nullable();
            $table->string('ik_rating')->nullable();
            $table->string('electrical_class')->nullable();
            $table->string('rl_ral')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
