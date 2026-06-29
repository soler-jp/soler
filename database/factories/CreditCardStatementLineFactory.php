<?php

namespace Database\Factories;

use App\Models\CreditCardStatement;
use App\Models\CreditCardStatementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCardStatementLine>
 */
class CreditCardStatementLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_card_statement_id' => CreditCardStatement::factory(),
            'line_number' => $this->faker->unique()->numberBetween(1, 1000),
            'used_on' => $this->faker->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'posted_on' => $this->faker->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'merchant_name' => $this->faker->company(),
            'description' => $this->faker->sentence(3),
            'amount' => $this->faker->numberBetween(500, 30000),
            'fingerprint' => $this->faker->uuid(),
            'status' => CreditCardStatementLine::STATUS_UNREVIEWED,
            'is_active' => true,
            'raw_payload' => ['source' => 'factory'],
        ];
    }
}
