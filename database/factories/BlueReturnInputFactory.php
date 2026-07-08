<?php

namespace Database\Factories;

use App\Models\BlueReturnInput;
use App\Models\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlueReturnInput>
 */
class BlueReturnInputFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fiscal_year_id' => FiscalYear::factory(),
            'key' => BlueReturnInput::KEY_RENT_EXPENSES,
            'value' => [
                'rows' => [
                    [
                        'address' => fake()->address(),
                        'name' => fake()->company(),
                        'rent_amount' => fake()->numberBetween(1_000, 100_000),
                        'deductible_amount' => fake()->numberBetween(1_000, 100_000),
                    ],
                ],
            ],
        ];
    }
}
