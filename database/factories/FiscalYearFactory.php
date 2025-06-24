<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\BusinessUnit;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FiscalYear>
 */
class FiscalYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year' => now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'is_active' => true,
            'is_closed' => false,
            'business_unit_id' => BusinessUnit::factory(),
        ];
    }
}
