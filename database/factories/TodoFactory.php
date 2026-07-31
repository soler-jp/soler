<?php

namespace Database\Factories;

use App\Models\BusinessUnit;
use App\Models\Todo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Todo>
 */
class TodoFactory extends Factory
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
            'fiscal_year_id' => null,
            'source_type' => Todo::SOURCE_TYPE_MANUAL,
            'source_model_type' => null,
            'source_model_id' => null,
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->optional()->sentence(),
            'due_on' => $this->faker->optional()->dateTimeBetween('-1 week', '+1 month'),
            'priority' => Todo::PRIORITY_NORMAL,
            'status' => Todo::STATUS_PENDING,
            'completed_at' => null,
            'dismissed_at' => null,
        ];
    }
}
