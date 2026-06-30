<?php

namespace Tests\Feature\FixedAsset;

use App\Models\DepreciationEntry;
use App\Models\User;
use App\Services\DepreciationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DepreciationEntryPropertyMapTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function depreciation_entryのラベルと参照先が対応している(): void
    {
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
                'name' => 'ラベル対応テスト資産',
                'asset_category' => 'machinery',
                'acquisition_date' => '2025-10-01',
                'taxable_amount' => 480_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 48,
                'business_usage_ratio' => 0.80,
            ],
            transactionData: [
                'date' => '2025-10-01',
                'description' => 'ラベル対応テスト資産を購入',
            ],
        );

        $entry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->firstOrFail();
        $entry->loadMissing('fixedAsset');

        $assetName = $entry->fixedAsset->name;
        $quantity = 1;

        $this->assertSame('ラベル対応テスト資産', $assetName, '減価償却資産の名称等');
        $this->assertSame(1, $quantity, '面積または数量');
        $this->assertSame('2025-10', $entry->acquisition_year_month, '取得年月');
        $this->assertSame(480_000, $entry->depreciation_base_amount, '償却の基礎になる金額');
        $this->assertSame('straight_line', $entry->depreciation_method, '償却方法');
        $this->assertSame(4, $entry->useful_life, '耐用年数');
        $this->assertSame('0.250', $entry->depreciation_rate, '償却率');
        $this->assertSame(3, $entry->months, '本年中の償却期間');
        $this->assertSame(30_000, $entry->ordinary_amount, '本年分の普通償却費');
        $this->assertSame(30_000, $entry->total_amount, '本年分の償却費合計');
        $this->assertSame('0.80', $entry->business_usage_ratio, '事業専用割合');
        $this->assertSame(24_000, $entry->deductible_amount, '本年分の必要経費算入額');
        $this->assertSame(450_000, $entry->depreciation_base_amount - $entry->ordinary_amount, '未償却残高(期末残高)');
    }
}
