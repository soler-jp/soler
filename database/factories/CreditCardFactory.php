<?php

namespace Database\Factories;

use App\Models\BusinessUnit;
use App\Models\CreditCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCard>
 */
class CreditCardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_unit_id' => BusinessUnit::factory(),
            'name' => $this->faker->company().'カード',
            'issuer_name' => $this->faker->randomElement(['Orico', 'AEON', 'SAISON']),
            'network' => $this->faker->randomElement(['visa', 'mastercard', 'jcb']),
            'last_four' => (string) $this->faker->numberBetween(1000, 9999),
            'ownership_type' => CreditCard::OWNERSHIP_TYPE_BUSINESS,
            'parser_key' => 'generic_csv_v1',
            'is_active' => true,
        ];
    }
}
