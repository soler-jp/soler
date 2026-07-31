<?php

namespace Database\Factories;

use App\Models\BusinessUnit;
use App\Models\InitialSetupData;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InitialSetupData>
 */
class InitialSetupDataFactory extends Factory
{
    protected $model = InitialSetupData::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_unit_id' => BusinessUnit::factory(),
            'year' => now()->year,
            'opening_context' => InitialSetupData::OPENING_CONTEXT_FIRST_YEAR,
            'is_taxable' => false,
            'bank_account_answer' => InitialSetupData::ANSWER_NO,
            'cash_on_hand_answer' => InitialSetupData::ANSWER_NO,
            'fixed_asset_answer' => InitialSetupData::ANSWER_NO,
            'recurring_expense_answer' => InitialSetupData::ANSWER_NO,
            'recurring_income_answer' => InitialSetupData::ANSWER_NO,
            'counterparty_answer' => InitialSetupData::ANSWER_NO,
            'completed_at' => now(),
        ];
    }
}
