<?php

namespace Database\Factories;

use App\Models\BusinessUnit;
use App\Models\Counterparty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Counterparty>
 */
class CounterpartyFactory extends Factory
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
            'name' => $this->faker->company,
            'registration_number' => 'T'.$this->faker->numerify('#############'),
            'is_qualified_invoice_issuer' => $this->faker->boolean(35),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
