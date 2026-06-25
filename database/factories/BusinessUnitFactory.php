<?php

namespace Database\Factories;

use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessUnit>
 */
class BusinessUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            'type' => BusinessUnit::TYPE_GENERAL,
            'user_id' => User::factory(),
        ];
    }
}
