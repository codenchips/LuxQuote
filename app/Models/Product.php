<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'site', 'product_name', 'sku', 'price', 'description', 'type_name',
    'length_mm', 'width_mm', 'depth_mm', 'diameter_mm', 'cut_out_mm',
    'weight_kg', 'luminaire_wattage_w', 'lumens_lm', 'efficacy_llm_w',
    'beam_angle_fwhm', 'emergency_lumen_output', 'power', 'em_power',
    'cct_k', 'colour_temp', 'cri', 'dali', 'vision_type', 'emergency_type',
    'ip_rating', 'ik_rating', 'electrical_class', 'rl_ral',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }
}
