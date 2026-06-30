<?php

namespace Tests\Feature\FixedAsset;

use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\User;
use App\Services\DepreciationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DepreciationEntryScenarioTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('registrationCases')]
    public function depreciation_entryの各種値を取得できる(array $case): void
    {
        $scenarioLabel = $case['ラベル'];
        $acquisitionDate = $case['取得年月'];
        $usefulLifeMonths = $case['耐用年数(月)'];
        $expectedUsefulLifeYears = $case['耐用年数(年)'];
        $taxableAmount = $case['償却の基礎になる金額'];
        $expectedMonths = $case['本年中の償却期間'];
        $expectedDepreciationRate = $case['償却率'];
        $expectedOrdinaryAmount = $case['本年分の普通償却費'];
        $expectedEndingUndepreciatedBalance = $case['未償却残高(期末残高)'];

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', '機械装置');
            })
            ->firstOrFail();

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            fiscalYear: $fiscalYear,
            assetSubAccount: $assetSubAccount,
            paymentSubAccount: $paymentSubAccount,
            fixedAssetData: [
                'name' => "{$scenarioLabel}の固定資産",
                'asset_category' => 'machinery',
                'acquisition_date' => $acquisitionDate,
                'taxable_amount' => $taxableAmount,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => $usefulLifeMonths,
            ],
            transactionData: [
                'date' => $acquisitionDate,
                'description' => "{$scenarioLabel}の購入",
            ],
        );

        $entry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->firstOrFail();

        $this->assertScenarioLabelMapping($entry, $scenarioLabel, $acquisitionDate);
        $this->assertDepreciationCalculation(
            $entry,
            $expectedUsefulLifeYears,
            $expectedMonths,
            $expectedDepreciationRate,
            $expectedOrdinaryAmount,
            $expectedEndingUndepreciatedBalance
        );
    }

    public static function registrationCases(): array
    {
        return [
            '6年・年度開始月に取得' => [
                [
                    'ラベル' => '6年・年度開始月に取得',
                    '取得年月' => '2025-01-01',
                    '耐用年数(月)' => 72,
                    '耐用年数(年)' => 6,
                    '償却の基礎になる金額' => 720_000,
                    '本年中の償却期間' => 12,
                    '償却率' => '0.167',
                    '本年分の普通償却費' => 120_240,
                    '未償却残高(期末残高)' => 599_760,
                ],
            ],
            '6年・年度途中に取得' => [
                [
                    'ラベル' => '6年・年度途中に取得',
                    '取得年月' => '2025-10-01',
                    '耐用年数(月)' => 72,
                    '耐用年数(年)' => 6,
                    '償却の基礎になる金額' => 720_000,
                    '本年中の償却期間' => 3,
                    '償却率' => '0.167',
                    '本年分の普通償却費' => 30_060,
                    '未償却残高(期末残高)' => 689_940,
                ],
            ],
            '4年・年度開始月に取得' => [
                [
                    'ラベル' => '4年・年度開始月に取得',
                    '取得年月' => '2025-01-01',
                    '耐用年数(月)' => 48,
                    '耐用年数(年)' => 4,
                    '償却の基礎になる金額' => 480_000,
                    '本年中の償却期間' => 12,
                    '償却率' => '0.250',
                    '本年分の普通償却費' => 120_000,
                    '未償却残高(期末残高)' => 360_000,
                ],
            ],
            '4年・年度途中に取得' => [
                [
                    'ラベル' => '4年・年度途中に取得',
                    '取得年月' => '2025-10-01',
                    '耐用年数(月)' => 48,
                    '耐用年数(年)' => 4,
                    '償却の基礎になる金額' => 480_000,
                    '本年中の償却期間' => 3,
                    '償却率' => '0.250',
                    '本年分の普通償却費' => 30_000,
                    '未償却残高(期末残高)' => 450_000,
                ],
            ],
        ];
    }

    private function assertScenarioLabelMapping(
        DepreciationEntry $entry,
        string $scenarioLabel,
        string $acquisitionDate
    ): void {
        $this->assertInstanceOf(FixedAsset::class, $entry->fixedAsset);
        $this->assertSame("{$scenarioLabel}の固定資産", $entry->fixedAsset->name, $scenarioLabel);
        $this->assertSame(substr($acquisitionDate, 0, 7), $entry->acquisition_year_month, $scenarioLabel);
        $this->assertSame($entry->fixedAsset->acquisition_cost, $entry->depreciation_base_amount, $scenarioLabel);
        $this->assertSame($entry->fixedAsset->depreciation_method, $entry->depreciation_method, $scenarioLabel);
    }

    private function assertDepreciationCalculation(
        DepreciationEntry $entry,
        int $expectedUsefulLifeYears,
        int $expectedMonths,
        string $expectedDepreciationRate,
        int $expectedOrdinaryAmount,
        int $expectedEndingUndepreciatedBalance
    ): void {
        $this->assertSame($expectedUsefulLifeYears, $entry->useful_life);
        $this->assertSame($expectedDepreciationRate, $entry->depreciation_rate);
        $this->assertSame($expectedMonths, $entry->months);
        $this->assertSame($expectedOrdinaryAmount, $entry->ordinary_amount);
        $this->assertSame('1.00', $entry->business_usage_ratio);
        $this->assertSame($expectedOrdinaryAmount, $entry->deductible_amount);
        $this->assertSame($expectedEndingUndepreciatedBalance, $entry->ending_undepreciated_balance);
    }
}
