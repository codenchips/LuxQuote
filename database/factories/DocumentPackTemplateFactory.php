<?php

namespace Database\Factories;

use App\Enums\ProjectVisibility;
use App\Models\DocumentPackTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentPackTemplate>
 */
class DocumentPackTemplateFactory extends Factory
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
            'name' => fake()->unique()->words(3, true),
            'visibility' => ProjectVisibility::Open,
            'team_id' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
