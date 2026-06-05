<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site' => fake()->randomElement(['xcite', 'tamlite', 'luxena']),
            'product_name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('??-####-??')),
            'price' => fake()->optional()->randomFloat(2, 1, 500),
            'description' => fake()->optional()->sentence(),
            'type_name' => fake()->randomElement(['Bollards', 'LED Strip', 'Downlights', 'Floodlights', null]),
            'length_mm' => fake()->optional()->numerify('###'),
            'width_mm' => fake()->optional()->numerify('###'),
            'depth_mm' => fake()->optional()->numerify('###'),
            'diameter_mm' => fake()->optional()->numerify('###'),
            'cut_out_mm' => null,
            'weight_kg' => fake()->optional()->randomFloat(2, 0.1, 10),
            'luminaire_wattage_w' => fake()->optional()->randomElement(['10W', '20W', '10W/20W/30W']),
            'lumens_lm' => fake()->optional()->numerify('####'),
            'efficacy_llm_w' => fake()->optional()->numerify('###'),
            'beam_angle_fwhm' => null,
            'emergency_lumen_output' => null,
            'power' => null,
            'em_power' => null,
            'cct_k' => fake()->optional()->randomElement(['3000K', '4000K', '3000K/4000K']),
            'colour_temp' => fake()->optional()->randomElement(['WW', 'NW', 'CW']),
            'cri' => fake()->optional()->randomElement(['80', '90']),
            'dali' => null,
            'vision_type' => null,
            'emergency_type' => null,
            'ip_rating' => fake()->optional()->randomElement(['IP20', 'IP44', 'IP65']),
            'ik_rating' => fake()->optional()->randomElement(['IK08', 'IK10']),
            'electrical_class' => fake()->optional()->randomElement(['Class 1', 'Class 2', 'Class 3']),
            'rl_ral' => null,
        ];
    }
}
