<?php

namespace Database\Factories;

use App\Models\ReportingEvent;
use App\Models\ReportingEventProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportingEventProduct>
 */
class ReportingEventProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reporting_event_id' => ReportingEvent::factory(),
            'product_id' => null,
            'code' => strtoupper(fake()->bothify('??###')),
            'description' => fake()->words(3, true),
            'quantity' => fake()->numberBetween(1, 100),
        ];
    }
}
