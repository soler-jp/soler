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
            'qualification_status' => Counterparty::QUALIFICATION_STATUS_UNKNOWN,
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function qualified(): static
    {
        return $this->state([
            'qualification_status' => Counterparty::QUALIFICATION_STATUS_QUALIFIED,
        ]);
    }

    public function nonQualified(): static
    {
        return $this->state([
            'qualification_status' => Counterparty::QUALIFICATION_STATUS_NON_QUALIFIED,
        ]);
    }
}
