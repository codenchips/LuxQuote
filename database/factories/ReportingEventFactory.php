<?php

namespace Database\Factories;

use App\Models\ReportingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportingEvent>
 */
class ReportingEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_log_id' => null,
            'event_type' => fake()->randomElement(['login', 'project_created', 'schedule', 'quote', 'document_pack']),
            'generation_batch_key' => fake()->uuid(),
            'occurred_at' => now(),
            'user_id' => null,
            'user_name_snapshot' => fake()->name(),
            'user_email_snapshot' => fake()->safeEmail(),
            'project_id' => null,
            'project_reference_snapshot' => null,
            'project_name_snapshot' => null,
            'owner_name_snapshot' => null,
            'owner_email_snapshot' => null,
            'revision_number' => null,
            'currency' => null,
            'net_value' => null,
            'gross_value' => null,
            'has_cover' => null,
            'effective_cover_percentage' => null,
            'include_datasheets' => null,
            'include_cover_letter' => null,
            'include_legal_page' => null,
            'tender_count' => null,
            'document_count' => null,
            'metadata' => null,
        ];
    }
}
