<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Account;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
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
            'business_unit_id' => \App\Models\BusinessUnit::factory(),
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
