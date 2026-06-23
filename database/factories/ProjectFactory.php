<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVisibility;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->sentence(3),
            'reference_number' => null,
            'customer_name' => $this->faker->company(),
            'contractor' => null,
            'site_location' => null,
            'owner_email' => $this->faker->safeEmail(),
            'created_by_email' => $this->faker->safeEmail(),
            'department' => null,
            'date' => $this->faker->date(),
            'revision' => 0,
            'visibility' => ProjectVisibility::Open->value,
            'status' => ProjectStatus::Draft->value,
            'branch_name' => null,
            'cover_percentage' => null,
            'value' => null,
            'quote_notes' => null,
            'internal_notes' => null,
            'general_notes' => null,
        ];
    }
}
