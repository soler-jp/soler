<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BusinessUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
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
            'name' => $this->faker->word,
            'type' => $this->faker->randomElement(['asset', 'liability', 'equity', 'revenue', 'expense']),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Account $account) {
            $account->subAccounts()->create([
                'name' => $account->name,
            ]);
        });
    }
}
