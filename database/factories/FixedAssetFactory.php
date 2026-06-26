<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\FixedAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixedAsset>
 */
class FixedAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $businessUnit = BusinessUnit::factory()->create();

        $account = Account::firstOrCreate(
            [
                'business_unit_id' => $businessUnit->id,
                'name' => '車両運搬具',
            ],
            [
                'type' => Account::TYPE_ASSET,
            ]
        );

        return [
            'business_unit_id' => $businessUnit->id,
            'account_id' => $account->id,
            'asset_category' => '新車-普通車',
            'name' => '新車-普通車',
            'acquisition_date' => $this->faker->date(),
            'taxable_amount' => 2_000_000,
            'tax_amount' => 200_000,
            'depreciation_base_amount' => 2_200_000,
            'useful_life' => 72,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
            'is_disposed' => false,
        ];
    }
}
