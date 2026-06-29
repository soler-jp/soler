<?php

namespace Database\Factories;

use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCardStatement>
 */
class CreditCardStatementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_card_id' => CreditCard::factory(),
            'statement_year' => (int) now()->format('Y'),
            'statement_month' => (int) now()->format('n'),
            'period_start_on' => now()->startOfMonth()->toDateString(),
            'period_end_on' => now()->endOfMonth()->toDateString(),
            'billed_on' => now()->endOfMonth()->toDateString(),
            'paid_on' => now()->copy()->addMonthNoOverflow()->day(27)->toDateString(),
            'total_amount' => $this->faker->numberBetween(1000, 50000),
            'line_count' => $this->faker->numberBetween(1, 20),
            'imported_at' => now(),
        ];
    }
}
