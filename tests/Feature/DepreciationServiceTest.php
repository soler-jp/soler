<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\DepreciationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DepreciationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 固定資産を登録すると取得仕訳も同時に登録される_免税事業者()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);

        $fiscalYear = $unit->createFiscalYear(2023);

        // 免税事業者, 税込価格
        $fiscalYear->update(['is_taxable_supplier' => false]);
        $fiscalYear->update(['is_tax_exclusive' => false]);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', '機械装置');
            })
            ->first();

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', 'その他の預金');
            })
            ->first();

        $fixedAssetData = [
            'name' => 'ノートPC',
            'asset_category' => 'furniture_fixtures',
            'acquisition_date' => '2023-06-01',
            'taxable_amount' => 150000,
            'tax_type' => 'taxable_purchases_10',
            'tax_amount' => 15000,
            'depreciation_method' => 'straight_line',
            'useful_life' => 36,
        ];

        $transactionData = [
            'date' => '2023-06-01',
            'description' => 'ノートPCを購入',
        ];

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            $fixedAssetData,
            $transactionData,
        );

        $this->assertDatabaseHas('fixed_assets', [
            'name' => 'ノートPC',
            'business_unit_id' => $unit->id,
            'taxable_amount' => 150000,
            'tax_amount' => 15000,
            'acquisition_cost' => 165000,
            'depreciation_base_amount' => 165000,
        ]);

        $this->assertDatabaseHas('transactions', [
            'description' => 'ノートPCを購入',
            'fiscal_year_id' => $fiscalYear->id,
        ]);

        $transaction = Transaction::where('description', 'ノートPCを購入')->first();
        $this->assertCount(2, $transaction->journalEntries);

        $this->assertTrue(
            $transaction->journalEntries->contains(
                fn ($e) => $e->type === 'debit' &&
                    $e->sub_account_id === $assetSubAccount->id &&
                    $e->net_amount === 165000
            )
        );

        $this->assertTrue(
            $transaction->journalEntries->contains(
                fn ($e) => $e->type === 'credit' &&
                    $e->sub_account_id === $paymentSubAccount->id &&
                    $e->net_amount === 165000
            )
        );
    }

    #[Test]
    public function 普通車カテゴリで登録すると耐用年数72ヶ月で償却予定が作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear,
            $paymentSubAccount,
            [
                'name' => 'PRIUS',
                'acquisition_date' => '2025-10-03',
                'taxable_amount' => 30_000_000,
                'tax_amount' => 0,
            ],
            [
                'date' => '2025-10-03',
                'description' => 'PRIUSを購入',
            ],
        );

        $this->assertDatabaseHas('fixed_assets', [
            'id' => $fixedAsset->id,
            'asset_category' => '新車-普通車',
            'useful_life' => 72,
            'depreciation_method' => 'straight_line',
        ]);
    }

    #[Test]
    public function 軽自動車カテゴリで登録すると耐用年数48ヶ月で償却予定が作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewLightCar(
            $fiscalYear,
            $paymentSubAccount,
            [
                'name' => 'N-BOX',
                'acquisition_date' => '2025-10-03',
                'taxable_amount' => 12_000_000,
                'tax_amount' => 0,
            ],
            [
                'date' => '2025-10-03',
                'description' => 'N-BOXを購入',
            ],
        );

        $this->assertDatabaseHas('fixed_assets', [
            'id' => $fixedAsset->id,
            'asset_category' => '新車-軽自動車',
            'useful_life' => 48,
            'depreciation_method' => 'straight_line',
        ]);
    }

    // 後回し
    //     #[Test]
    //     public function 固定資産を登録すると取得仕訳も同時に登録される_課税事業者_税別仕訳()
    //     {
    //         $user = User::factory()->create();
    //         $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
    //         $fiscalYear = $unit->createFiscalYear(2023);

    //         // 課税事業者
    //         $fiscalYear->update(['is_taxable' => true]);

    //         // 税別仕訳
    //         $fiscalYear->update(['is_tax_exclusive' => true]);

    //         $assetAccount = $unit->accounts()->where('name', '機械装置')->first();
    //         $paymentAccount = $unit->accounts()->where('name', 'その他の預金')->first();

    //         // 仮払消費税アカウントを追加（明示）
    //         $taxAccount = $unit->accounts()->create([
    //             'name' => '仮払消費税',
    //             'type' => 'asset',
    //         ]);

    //         $fixedAssetData = [
    //             'name' => 'ノートPC',
    //             'asset_category' => 'furniture_fixtures',
    //             'acquisition_date' => '2023-06-01',
    //             'taxable_amount' => 150000,
    //             'tax_type' => 'taxable_purchases_10',
    //             'tax_amount' => 15000,
    //             'depreciation_method' => 'straight_line',
    //             'useful_life' => 36,
    //         ];

    //         $transactionData = [
    //             'date' => '2023-06-01',
    //             'description' => 'ノートPCを購入',
    //         ];

    //         $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
    //             $fiscalYear,
    //             $assetAccount,
    //             $paymentAccount,
    //             $fixedAssetData,
    //             $transactionData,
    //         );

    //         $this->assertDatabaseHas('fixed_assets', [
    //             'name' => 'ノートPC',
    //             'business_unit_id' => $unit->id,
    //             'taxable_amount' => 150000,
    //             'tax_amount' => 15000,
    //             'acquisition_cost' => 165000,
    //             'depreciation_base_amount' => 150000,
    //         ]);

    //         $transaction = Transaction::where('description', 'ノートPCを購入')->first();
    //         $this->assertCount(3, $transaction->journalEntries);

    //         $this->assertTrue(
    //             $transaction->journalEntries->contains(
    //                 fn($e) =>
    //                 $e->type === 'debit' &&
    //                     $e->account_id === $assetAccount->id &&
    //                     $e->net_amount === 150000
    //             )
    //         );

    //         $this->assertTrue(
    //             $transaction->journalEntries->contains(
    //                 fn($e) =>
    //                 $e->type === 'debit' &&
    //                     $e->account->name === '仮払消費税' &&
    //                     $e->net_amount === 15000
    //             )
    //         );

    //         $this->assertTrue(
    //             $transaction->journalEntries->contains(
    //                 fn($e) =>
    //                 $e->type === 'credit' &&
    //                     $e->account_id === $paymentAccount->id &&
    //                     $e->net_amount === 165000
    //             )
    //         );
    //     }
    //
}
