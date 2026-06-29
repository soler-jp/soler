<?php

namespace Database\Factories;

use App\Models\CreditCardImportBatch;
use App\Models\CreditCardStatement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCardImportBatch>
 */
class CreditCardImportBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rowCount = $this->faker->numberBetween(1, 20);

        return [
            'credit_card_statement_id' => CreditCardStatement::factory(),
            'uploaded_by' => User::factory(),
            'source_filename' => 'statement.csv',
            'source_hash' => sha1((string) $this->faker->uuid()),
            'parser_key' => 'generic_csv_v1',
            'status' => CreditCardImportBatch::STATUS_COMPLETED,
            'is_active' => true,
            'row_count' => $rowCount,
            'success_count' => $rowCount,
            'duplicate_count' => 0,
            'error_count' => 0,
            'imported_at' => now(),
        ];
    }
}
