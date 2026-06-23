<?php

namespace Database\Factories;

use App\Models\DocumentPack;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentPack> */
class DocumentPackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->words(3, true),
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }
}
