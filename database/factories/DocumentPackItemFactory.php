<?php

namespace Database\Factories;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Models\DocumentPack;
use App\Models\DocumentPackItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentPackItem> */
class DocumentPackItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_pack_id' => DocumentPack::factory(),
            'role' => DocumentPackItemRole::UnpricedSchedule,
            'source_type' => DocumentPackItemSource::Generated,
            'sort_order' => 0,
            'file_disk' => null,
            'file_path' => null,
            'original_filename' => null,
            'configuration' => null,
        ];
    }
}
