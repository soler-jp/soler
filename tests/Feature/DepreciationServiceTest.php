<?php

namespace Tests\Feature;

use App\Models\DepreciationEntry;
use App\Models\JournalEntry;
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
        ]);

        $this->assertSame(165000, $fixedAsset->acquisition_cost);

        $this->assertDatabaseHas('transactions', [
            'description' => 'ノートPCを購入',
            'fiscal_year_id' => $fiscalYear->id,
        ]);

        $this->assertDatabaseHas('depreciation_entries', [
            'fiscal_year_id' => $fiscalYear->id,
            'fixed_asset_id' => $fixedAsset->id,
            'months' => 7,
            'ordinary_amount' => 32081,
            'special_amount' => 0,
            'total_amount' => 32081,
            'business_usage_ratio' => 1.00,
            'deductible_amount' => 32081,
            'transaction_id' => null,
        ]);

        $entry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->first();
        $this->assertNotNull($entry);
        $this->assertTrue($entry->isUnposted());

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

    #[Test]
    public function 取得年度と登録年度が同じ場合は_entry1件だけ作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', '機械装置'))
            ->firstOrFail();
        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear2023,
            $assetSubAccount,
            $paymentSubAccount,
            [
                'name' => 'ノートPC',
                'asset_category' => 'furniture_fixtures',
                'acquisition_date' => '2023-04-01',
                'taxable_amount' => 120000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 36,
            ],
            ['date' => '2023-04-01', 'description' => 'ノートPC購入'],
        );

        $this->assertSame(1, DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->count());

        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2023->id,
            'months' => 9, // 4月〜12月
        ]);
    }

    #[Test]
    public function 過去年度に取得した固定資産を登録すると取得年度から登録年度まで全年度の_entryが作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $fiscalYear2023 = $unit->createFiscalYear(2023);
        $fiscalYear2024 = $unit->createFiscalYear(2024);
        $fiscalYear2025 = $unit->createFiscalYear(2025);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', '機械装置'))
            ->firstOrFail();
        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        // 耐用年数60ヶ月 → 月額 = floor(600_000 / 60) = 10_000円
        // 2023年10月取得の資産を2025年度で登録
        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear2025,
            $assetSubAccount,
            $paymentSubAccount,
            [
                'name' => 'サーバー機器',
                'asset_category' => 'machinery',
                'acquisition_date' => '2023-10-01',
                'taxable_amount' => 600_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 60,
            ],
            ['date' => '2025-01-01', 'description' => 'サーバー機器購入（過去年度）'],
        );

        $this->assertSame(3, DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->count());

        // 2023年: 10月〜12月 = 3ヶ月
        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2023->id,
            'months' => 3,
            'ordinary_amount' => 30_000,
            'total_amount' => 30_000,
            'deductible_amount' => 30_000,
        ]);

        // 2024年: 1月〜12月 = 12ヶ月
        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2024->id,
            'months' => 12,
            'ordinary_amount' => 120_000,
            'total_amount' => 120_000,
            'deductible_amount' => 120_000,
        ]);

        // 2025年: 1月〜12月 = 12ヶ月
        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2025->id,
            'months' => 12,
            'ordinary_amount' => 120_000,
            'total_amount' => 120_000,
            'deductible_amount' => 120_000,
        ]);
    }

    #[Test]
    public function d_bに存在しない中間年度は_entryをスキップする()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $fiscalYear2023 = $unit->createFiscalYear(2023);
        // 2024年度はDBに作成しない
        $fiscalYear2025 = $unit->createFiscalYear(2025);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', '機械装置'))
            ->firstOrFail();
        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear2025,
            $assetSubAccount,
            $paymentSubAccount,
            [
                'name' => 'コピー機',
                'asset_category' => 'machinery',
                'acquisition_date' => '2023-01-01',
                'taxable_amount' => 360_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 60,
            ],
            ['date' => '2025-01-01', 'description' => 'コピー機購入（過去年度）'],
        );

        // 2024年度が存在しないので2件だけ
        $this->assertSame(2, DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->count());

        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2023->id,
        ]);
        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2025->id,
        ]);
    }

    #[Test]
    public function 事業使用割合が設定された場合は必要経費算入額が按分される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2024 = $unit->createFiscalYear(2024);
        $fiscalYear2025 = $unit->createFiscalYear(2025);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', '機械装置'))
            ->firstOrFail();
        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        // 耐用年数48ヶ月 → 月額 = floor(1_200_000 / 48) = 25_000円
        // 事業使用割合80%、2024年1月取得
        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear2025,
            $assetSubAccount,
            $paymentSubAccount,
            [
                'name' => '自家用兼事業用PC',
                'asset_category' => 'furniture_fixtures',
                'acquisition_date' => '2024-01-01',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 48,
                'business_usage_ratio' => 0.80,
            ],
            ['date' => '2025-01-01', 'description' => '自家用兼事業用PC購入'],
        );

        // 2024年: 12ヶ月 × 25_000 = 300_000 → 80% = 240_000
        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2024->id,
            'months' => 12,
            'ordinary_amount' => 300_000,
            'total_amount' => 300_000,
            'business_usage_ratio' => '0.80',
            'deductible_amount' => 240_000,
        ]);

        // 2025年: 12ヶ月 × 25_000 = 300_000 → 80% = 240_000
        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2025->id,
            'months' => 12,
            'ordinary_amount' => 300_000,
            'business_usage_ratio' => '0.80',
            'deductible_amount' => 240_000,
        ]);
    }

    #[Test]
    public function 年度末月に取得した場合は1ヶ月分の_entryが作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2025 = $unit->createFiscalYear(2025);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', '機械装置'))
            ->firstOrFail();
        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear2025,
            $assetSubAccount,
            $paymentSubAccount,
            [
                'name' => '年末購入機器',
                'asset_category' => 'machinery',
                'acquisition_date' => '2025-12-15',
                'taxable_amount' => 120_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 60,
            ],
            ['date' => '2025-12-15', 'description' => '年末購入機器'],
        );

        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2025->id,
            'months' => 1,
        ]);
    }

    #[Test]
    public function 年度初日に取得した場合は12ヶ月分の_entryが作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2025 = $unit->createFiscalYear(2025);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', '機械装置'))
            ->firstOrFail();
        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear2025,
            $assetSubAccount,
            $paymentSubAccount,
            [
                'name' => '年初購入機器',
                'asset_category' => 'machinery',
                'acquisition_date' => '2025-01-01',
                'taxable_amount' => 120_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 60,
            ],
            ['date' => '2025-01-01', 'description' => '年初購入機器'],
        );

        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2025->id,
            'months' => 12,
        ]);
    }

    #[Test]
    public function register_transaction_forは未記帳entryから減価償却仕訳を作成する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2025 = $unit->createFiscalYear(2025);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', '機械装置'))
            ->firstOrFail();
        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();
        $expenseSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', '減価償却費'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear2025,
            $assetSubAccount,
            $paymentSubAccount,
            [
                'name' => '工作機械',
                'asset_category' => 'machinery',
                'acquisition_date' => '2025-01-01',
                'taxable_amount' => 120_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 60,
            ],
            ['date' => '2025-01-01', 'description' => '工作機械購入'],
        );

        $entry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->firstOrFail();

        app(DepreciationService::class)->registerTransactionFor($entry);

        $entry->refresh();

        $this->assertNotNull($entry->transaction_id);
        $this->assertFalse($entry->isUnposted());

        $transaction = Transaction::findOrFail($entry->transaction_id);

        $this->assertSame($fiscalYear2025->id, $transaction->fiscal_year_id);
        $this->assertSame('2025-12-31', $transaction->date->toDateString());
        $this->assertSame('2025年 減価償却: 工作機械', $transaction->description);
        $this->assertTrue($transaction->is_adjusting_entry);
        $this->assertCount(2, $transaction->journalEntries);

        $this->assertTrue(
            $transaction->journalEntries->contains(
                fn (JournalEntry $journalEntry) => $journalEntry->type === JournalEntry::TYPE_DEBIT
                    && $journalEntry->sub_account_id === $expenseSubAccount->id
                    && $journalEntry->net_amount === $entry->deductible_amount
                    && $journalEntry->tax_type === JournalEntry::TAX_TYPE_NON_TAXABLE
            )
        );

        $this->assertTrue(
            $transaction->journalEntries->contains(
                fn (JournalEntry $journalEntry) => $journalEntry->type === JournalEntry::TYPE_CREDIT
                    && $journalEntry->sub_account_id === $assetSubAccount->id
                    && $journalEntry->net_amount === $entry->deductible_amount
                    && $journalEntry->tax_type === JournalEntry::TAX_TYPE_NON_TAXABLE
            )
        );
    }

    #[Test]
    public function register_transaction_forは記帳済みentryを再記帳できない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2025 = $unit->createFiscalYear(2025);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', '機械装置'))
            ->firstOrFail();
        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear2025,
            $assetSubAccount,
            $paymentSubAccount,
            [
                'name' => '検査装置',
                'asset_category' => 'machinery',
                'acquisition_date' => '2025-01-01',
                'taxable_amount' => 120_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 60,
            ],
            ['date' => '2025-01-01', 'description' => '検査装置購入'],
        );

        $entry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->firstOrFail();
        $service = app(DepreciationService::class);

        $service->registerTransactionFor($entry);

        $this->expectException(\InvalidArgumentException::class);

        $service->registerTransactionFor($entry->fresh());
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
