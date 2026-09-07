<?php

namespace Database\Factories;

use App\Models\PdfGeneration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PdfGeneration>
 */
class PdfGenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'type' => PdfGeneration::TypeSchedule,
            'status' => PdfGeneration::StatusQueued,
            'progress' => 0,
            'attempt_count' => 0,
            'message' => 'Waiting for a PDF worker...',
            'parameters' => ['revision' => 1],
        ];
    }
}
