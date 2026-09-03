<?php

namespace Database\Factories;

use App\Models\ResourceFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ResourceFile>
 */
class ResourceFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => fake()->words(3, true),
            'file_disk' => ResourceFile::Disk,
            'file_path' => ResourceFile::Directory.'/'.Str::uuid().'.pdf',
            'original_filename' => fake()->slug().'.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => fake()->numberBetween(1000, 1000000),
            'uploaded_by_id' => User::factory(),
        ];
    }
}
