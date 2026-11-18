<?php

namespace Database\Factories;

use App\Models\SalesforceCustomerDefault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesforceCustomerDefault>
 */
class SalesforceCustomerDefaultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'salesforce_account_id' => '001'.fake()->unique()->regexify('[A-Za-z0-9]{15}'),
            'cover_1' => 5,
            'cover_2' => 5,
            'cover_3' => 5,
        ];
    }
}
