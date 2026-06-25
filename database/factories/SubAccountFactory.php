<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SubAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubAccount>
 */
class SubAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => $this->faker->word,
        ];
    }
}
//
