<?php

namespace Database\Factories;

use App\Enums\DocumentPackItemRole;
use App\Enums\DocumentPackItemSource;
use App\Models\DocumentPackTemplate;
use App\Models\DocumentPackTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentPackTemplateItem>
 */
class DocumentPackTemplateItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_pack_template_id' => DocumentPackTemplate::factory(),
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
