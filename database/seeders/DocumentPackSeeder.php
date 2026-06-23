<?php

namespace Database\Seeders;

use App\Models\DocumentPack;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DocumentPackSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DocumentPack::factory()->create();
    }
}
