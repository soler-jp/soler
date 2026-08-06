<?php

namespace Tests\Feature;

use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\TransactionRegistrar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DepreciationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 去年取得した車を登録対象年度に登録すると例外になる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $summaryBefore = $fiscalYear->calculateSummary();

        try {
            app(DepreciationService::class)->registerNewStandardCar(
                $fiscalYear,
                $paymentSubAccount,
                [
                    'name' => '去年取得のPRIUS',
                    'acquisition_date' => '2024-10-03',
                    'taxable_amount' => 3_000_000,
                    'tax_amount' => 300_000,
                ],
                [
                    'date' => '2024-10-03',
                    'description' => '去年取得のPRIUSを購入',
                ],
            );

            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame($summaryBefore, $fiscalYear->calculateSummary());
            $this->assertDatabaseMissing('fixed_assets', [
                'name' => '去年取得のPRIUS',
            ]);
            $this->assertDatabaseMissing('transactions', [
                'fiscal_year_id' => $fiscalYear->id,
                'description' => '去年取得のPRIUSを購入',
            ]);
        }
    }

    #[Test]
    public function register_fixed_assetでallow_registrationを付けると去年取得の車を強制登録できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', '車両運搬具');
            })
            ->firstOrFail();

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $summaryBefore = $fiscalYear->calculateSummary();

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            [
                'name' => '去年取得のN-BOX',
                'asset_category' => '新車-軽自動車',
                'acquisition_date' => '2024-10-03',
                'taxable_amount' => 2_000_000,
                'tax_amount' => 200_000,
                'useful_life' => 48,
                'depreciation_method' => 'straight_line',
            ],
            [
                'date' => '2024-10-03',
                'description' => '去年取得のN-BOXを購入',
            ],
            true,
        );

        $this->assertModelExists($fixedAsset);
        $this->assertDatabaseMissing('transactions', [
            'fiscal_year_id' => $fiscalYear->id,
            'description' => '去年取得のN-BOXを購入',
        ]);
        $this->assertSame($summaryBefore, $fiscalYear->calculateSummary());
    }

    #[Test]
    public function 登録年度しか_fiscal_yearがない場合は存在する年度分だけ_entryが作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear2024 = $unit->createFiscalYear(2024, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewLightCar(
            $fiscalYear2024,
            $paymentSubAccount,
            [
                'name' => '過年度取得の軽自動車',
                'acquisition_date' => '2022-01-01',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 120_000,
            ],
            [
                'date' => '2024-01-01',
                'description' => '過年度取得の軽自動車を登録',
            ],
            true,
        );

        $entry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->firstOrFail();

        $this->assertSame(1, DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->count());
        $this->assertSame($fiscalYear2024->id, $entry->fiscal_year_id);
        $this->assertSame(12, $entry->months);
        $this->assertSame(330_000, $entry->ordinary_amount);
        $this->assertSame(330_000, $entry->total_amount);
        $this->assertSame(330_000, $entry->deductible_amount);
    }

    #[Test]
    public function 過去の_fiscal_yearがある場合は存在する年度分の_entryが作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);
        $fiscalYear2024 = $unit->createFiscalYear(2024, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewLightCar(
            $fiscalYear2024,
            $paymentSubAccount,
            [
                'name' => '過年度取得の軽自動車',
                'acquisition_date' => '2022-01-01',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 120_000,
            ],
            [
                'date' => '2024-01-01',
                'description' => '過年度取得の軽自動車を登録',
            ],
            true,
        );

        $entries = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)
            ->orderBy('fiscal_year_id')
            ->get()
            ->keyBy('fiscal_year_id');

        $this->assertSame(2, $entries->count());
        $this->assertDatabaseMissing('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $unit->fiscalYears()->where('year', 2022)->value('id'),
        ]);
        $this->assertSame(12, $entries[$fiscalYear2023->id]->months);
        $this->assertSame(330_000, $entries[$fiscalYear2023->id]->ordinary_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2023->id]->total_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2023->id]->deductible_amount);
        $this->assertSame(12, $entries[$fiscalYear2024->id]->months);
        $this->assertSame(330_000, $entries[$fiscalYear2024->id]->ordinary_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2024->id]->total_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2024->id]->deductible_amount);
    }

    #[Test]
    public function 後から_fiscal_yearを作成するとその年度の_entryが自動で補完される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewLightCar(
            $fiscalYear2025,
            $paymentSubAccount,
            [
                'name' => '後から年度が増える軽自動車',
                'acquisition_date' => '2025-10-01',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 120_000,
            ],
            [
                'date' => '2025-10-01',
                'description' => '後から年度が増える軽自動車を登録',
            ],
        );

        $this->assertSame(1, DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->count());

        $fiscalYear2026 = $unit->createFiscalYear(2026, $user);

        $entries = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)
            ->orderBy('fiscal_year_id')
            ->get()
            ->keyBy('fiscal_year_id');

        $this->assertSame(2, $entries->count());
        $this->assertSame($fiscalYear2025->id, $entries->keys()->first());
        $this->assertSame($fiscalYear2026->id, $entries->keys()->last());
        $this->assertSame(3, $entries[$fiscalYear2025->id]->months);
        $this->assertSame(82_500, $entries[$fiscalYear2025->id]->ordinary_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2026->id]->ordinary_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2026->id]->total_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2026->id]->deductible_amount);

        app(DepreciationService::class)->prepareEntriesFor($fiscalYear2026, $user);

        $this->assertSame(
            2,
            DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->count()
        );
    }

    #[Test]
    public function 年の途中で取得した軽自動車は取得月から年末までの_monthsで_entryが作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear2022 = $unit->createFiscalYear(2022, $user);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);
        $fiscalYear2024 = $unit->createFiscalYear(2024, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewLightCar(
            $fiscalYear2024,
            $paymentSubAccount,
            [
                'name' => '年の途中取得の軽自動車',
                'acquisition_date' => '2022-10-01',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 120_000,
            ],
            [
                'date' => '2024-01-01',
                'description' => '年の途中取得の軽自動車を登録',
            ],
            true,
        );

        $entries = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)
            ->orderBy('fiscal_year_id')
            ->get()
            ->keyBy('fiscal_year_id');

        $this->assertSame(3, $entries->count());
        $this->assertSame(3, $entries[$fiscalYear2022->id]->months);
        $this->assertSame(82_500, $entries[$fiscalYear2022->id]->ordinary_amount);
        $this->assertSame(82_500, $entries[$fiscalYear2022->id]->total_amount);
        $this->assertSame(82_500, $entries[$fiscalYear2022->id]->deductible_amount);
        $this->assertSame(12, $entries[$fiscalYear2023->id]->months);
        $this->assertSame(330_000, $entries[$fiscalYear2023->id]->ordinary_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2023->id]->total_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2023->id]->deductible_amount);
        $this->assertSame(12, $entries[$fiscalYear2024->id]->months);
        $this->assertSame(330_000, $entries[$fiscalYear2024->id]->ordinary_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2024->id]->total_amount);
        $this->assertSame(330_000, $entries[$fiscalYear2024->id]->deductible_amount);
    }

    #[Test]
    public function 固定資産を登録すると取得仕訳も同時に登録される_免税事業者()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);

        $fiscalYear = $unit->createFiscalYear(2023, $user);

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
            'ordinary_amount' => 32148,
            'special_amount' => 0,
            'total_amount' => 32148,
            'business_usage_ratio' => 1.00,
            'deductible_amount' => 32148,
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
        $fiscalYear = $unit->createFiscalYear(2025, $user);

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
    public function 減価償却予定は年度ごとの配列として取得できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);

        $assetSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', '機械装置');
            })
            ->firstOrFail();

        $asset = FixedAsset::create([
            'business_unit_id' => $unit->id,
            'account_id' => $assetSubAccount->account_id,
            'name' => 'サーバー機器',
            'asset_category' => 'machinery',
            'acquisition_date' => '2023-10-01',
            'taxable_amount' => 480000,
            'tax_amount' => 0,
            'useful_life' => 48,
            'depreciation_method' => 'straight_line',
        ]);

        $schedule = app(DepreciationService::class)->calculateDepreciationScheduleUntilFullyDepreciated(
            $asset,
        );

        $this->assertSame([
            2023 => [
                'months' => 3,
                'ordinary_amount' => 30000,
                'special_amount' => 0,
                'total_amount' => 30000,
                'ending_balance' => 450000,
            ],
            2024 => [
                'months' => 12,
                'ordinary_amount' => 120000,
                'special_amount' => 0,
                'total_amount' => 120000,
                'ending_balance' => 330000,
            ],
            2025 => [
                'months' => 12,
                'ordinary_amount' => 120000,
                'special_amount' => 0,
                'total_amount' => 120000,
                'ending_balance' => 210000,
            ],
            2026 => [
                'months' => 12,
                'ordinary_amount' => 120000,
                'special_amount' => 0,
                'total_amount' => 120000,
                'ending_balance' => 90000,
            ],
            2027 => [
                'months' => 9,
                'ordinary_amount' => 90000,
                'special_amount' => 0,
                'total_amount' => 90000,
                'ending_balance' => 0,
            ],
        ], $schedule);

        $this->assertArrayHasKey(2023, $schedule);
        $this->assertArrayHasKey(2024, $schedule);
        $this->assertArrayHasKey(2025, $schedule);
        $this->assertArrayHasKey(2026, $schedule);
        $this->assertArrayHasKey(2027, $schedule);
    }

    #[Test]
    public function is_fully_depreciatedは年度内に償却が発生しない場合だけtrueになる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);

        $fiscalYear2030 = $unit->createFiscalYear(2030, $user);
        $fiscalYear2031 = $unit->createFiscalYear(2031, $user);
        $fiscalYear2032 = $unit->createFiscalYear(2032, $user);

        $assetAccount = $unit->getAccountByName('車両運搬具');
        $this->assertNotNull($assetAccount);

        $ongoingAsset = FixedAsset::create([
            'business_unit_id' => $unit->id,
            'account_id' => $assetAccount->id,
            'name' => '償却継続中の車両',
            'asset_category' => '新車-普通車',
            'acquisition_date' => '2026-03-01',
            'taxable_amount' => 1_000_000,
            'tax_amount' => 100_000,
            'useful_life' => 72,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
            'is_disposed' => false,
        ]);

        $completedAsset = FixedAsset::create([
            'business_unit_id' => $unit->id,
            'account_id' => $assetAccount->id,
            'name' => '償却完了の車両',
            'asset_category' => '新車-普通車',
            'acquisition_date' => '2024-03-01',
            'taxable_amount' => 1_000_000,
            'tax_amount' => 100_000,
            'useful_life' => 72,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
            'is_disposed' => false,
        ]);

        $service = app(DepreciationService::class);

        // 2031と2032がスケジュールに含まれていることを確認
        $onGoingSchedule = $service->calculateDepreciationScheduleUntilFullyDepreciated($ongoingAsset);
        $this->assertArrayHasKey(2031, $onGoingSchedule);
        $this->assertArrayHasKey(2032, $onGoingSchedule);
        $this->assertFalse($service->isFullyDepreciated($ongoingAsset, $fiscalYear2031));
        $this->assertFalse($service->isFullyDepreciated($ongoingAsset, $fiscalYear2032));

        $completedSchedule = $service->calculateDepreciationScheduleUntilFullyDepreciated($completedAsset);
        // 2030がスケジュールに含まれていることを確認
        $this->assertArrayHasKey(2030, $completedSchedule);
        // 2031がスケジュールに含まれていないことを確認
        $this->assertArrayNotHasKey(2031, $completedSchedule);

        $this->assertTrue($service->isStillDepreciating($completedAsset, $fiscalYear2030));
        $this->assertFalse($service->isFullyDepreciated($completedAsset, $fiscalYear2030));
        $this->assertTrue($service->isFullyDepreciated($completedAsset, $fiscalYear2031));
        $this->assertFalse($service->isStillDepreciating($completedAsset, $fiscalYear2031));
    }

    #[Test]
    public function 軽自動車カテゴリで登録すると耐用年数48ヶ月で償却予定が作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

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
    #[DataProvider('usedVehicleUsefulLifeCases')]
    public function 中古車カテゴリで登録すると簡便法で耐用年数が設定される(
        string $registrationMethod,
        string $assetCategory,
        string $firstRegistrationDate,
        int $expectedUsefulLifeMonths,
    ): void {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->{$registrationMethod}(
            $fiscalYear,
            $paymentSubAccount,
            [
                'name' => '中古車',
                'first_registration_date' => $firstRegistrationDate,
                'acquisition_date' => '2025-10-03',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 0,
            ],
            [
                'date' => '2025-10-03',
                'description' => '中古車を購入',
            ],
        );

        $this->assertDatabaseHas('fixed_assets', [
            'id' => $fixedAsset->id,
            'asset_category' => $assetCategory,
            'useful_life' => $expectedUsefulLifeMonths,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
        ]);
        $this->assertSame($firstRegistrationDate, $fixedAsset->first_registration_date->toDateString());
    }

    #[Test]
    public function 中古車の初度登録日が取得日より後なら登録しない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('中古車の first_registration_date は acquisition_date 以前の日付を指定してください。');

        app(DepreciationService::class)->registerUsedStandardCar(
            $fiscalYear,
            $paymentSubAccount,
            [
                'name' => '未来登録の中古車',
                'first_registration_date' => '2025-10-04',
                'acquisition_date' => '2025-10-03',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 0,
            ],
            [
                'date' => '2025-10-03',
                'description' => '未来登録の中古車を購入',
            ],
        );
    }

    #[Test]
    public function 中古車の初度登録日がなければ登録しない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('中古車の登録には first_registration_date が必要です。');

        app(DepreciationService::class)->registerUsedLightCar(
            $fiscalYear,
            $paymentSubAccount,
            [
                'name' => '初度登録日なしの中古車',
                'acquisition_date' => '2025-10-03',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 0,
            ],
            [
                'date' => '2025-10-03',
                'description' => '初度登録日なしの中古車を購入',
            ],
        );
    }

    #[Test]
    public function 耐用年数を経過した中古車は24ヶ月で償却予定が作成される(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerUsedLightCar(
            $fiscalYear,
            $paymentSubAccount,
            [
                'name' => '10年落ちの軽自動車',
                'first_registration_date' => '2015-10-03',
                'acquisition_date' => '2025-10-03',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 0,
            ],
            [
                'date' => '2025-10-03',
                'description' => '10年落ちの軽自動車を購入',
            ],
        );

        $entry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->firstOrFail();

        $this->assertSame(24, $fixedAsset->useful_life);
        $this->assertSame(3, $entry->months);
        $this->assertSame(150_000, $entry->ordinary_amount);
        $this->assertSame(150_000, $entry->total_amount);
        $this->assertSame(150_000, $entry->deductible_amount);
    }

    public static function usedVehicleUsefulLifeCases(): array
    {
        return [
            '普通車_経過なし' => [
                'registerUsedStandardCar',
                FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
                '2025-10-03',
                72,
            ],
            '普通車_2年落ち' => [
                'registerUsedStandardCar',
                FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
                '2023-10-03',
                48,
            ],
            '普通車_3年落ち' => [
                'registerUsedStandardCar',
                FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
                '2022-10-03',
                36,
            ],
            '普通車_1年6ヶ月落ち' => [
                'registerUsedStandardCar',
                FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
                '2024-04-03',
                48,
            ],
            '普通車_2年未満の端数月は切り捨て' => [
                'registerUsedStandardCar',
                FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
                '2023-10-20',
                48,
            ],
            '普通車_10年落ち' => [
                'registerUsedStandardCar',
                FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
                '2015-10-03',
                24,
            ],
            '軽自動車_1年落ち' => [
                'registerUsedLightCar',
                FixedAsset::ASSET_CATEGORY_USED_LIGHT_CAR,
                '2024-10-03',
                36,
            ],
            '軽自動車_2年落ち' => [
                'registerUsedLightCar',
                FixedAsset::ASSET_CATEGORY_USED_LIGHT_CAR,
                '2023-10-03',
                24,
            ],
            '軽自動車_10年落ち' => [
                'registerUsedLightCar',
                FixedAsset::ASSET_CATEGORY_USED_LIGHT_CAR,
                '2015-10-03',
                24,
            ],
        ];
    }

    #[Test]
    public function 過去年度取得の中古車を登録年度で登録すると簡便法耐用年数で当年度分の_entryだけ作成される()
    {
        // 2022年初度登録の中古普通車を2025年に50万円で購入し、
        // 2025年度は Soler 管理外（別処理）で、2026年度から Soler で記帳するケース。
        // 取得仕訳は当年度外なので作られず、簡便法耐用年数(36ヶ月)で2026年度分だけ作成される。
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        // 2025年度は DB に作成しない（別処理を想定）
        $fiscalYear2026 = $unit->createFiscalYear(2026, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerUsedStandardCar(
            $fiscalYear2026,
            $paymentSubAccount,
            [
                'name' => '中古の営業車',
                'first_registration_date' => '2022-06-15',
                'acquisition_date' => '2025-06-15',
                'taxable_amount' => 500_000,
                'tax_amount' => 0,
            ],
            ['date' => '2025-06-15', 'description' => '中古の営業車を購入（過去年度）'],
            true,
        );

        // 簡便法: 経過36ヶ月 → (72 - 36 + 36 * 0.2) / 12 = 3.6 → 3年 = 36ヶ月
        $this->assertSame(36, $fixedAsset->useful_life);

        // 取得日(2025)が登録年度(2026)外なので取得仕訳は作られない
        $this->assertSame(0, $fixedAsset->businessUnit->fiscalYears()
            ->where('year', 2026)
            ->first()
            ->transactions()
            ->count());

        // 2025年度は DB に存在しないため、当年度(2026)分の Entry のみ
        $this->assertSame(1, DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->count());

        // 償却率 = ceil((12 / 36) * 1000) / 1000 = 0.334
        // 年額 = round(500_000 * 0.334) = 167_000、2026年は12ヶ月
        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $fixedAsset->id,
            'fiscal_year_id' => $fiscalYear2026->id,
            'months' => 12,
            'ordinary_amount' => 167_000,
            'total_amount' => 167_000,
            'deductible_amount' => 167_000,
        ]);
    }

    #[Test]
    public function 取得年度と登録年度が同じ場合は_entry1件だけ作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

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

        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);
        $fiscalYear2024 = $unit->createFiscalYear(2024, $user);
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

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
            true,
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

        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);
        // 2024年度はDBに作成しない
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

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
            true,
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
        $fiscalYear2024 = $unit->createFiscalYear(2024, $user);
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

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
            true,
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
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

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
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

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
    public function 年度途中取得の償却スケジュールは月数を整数で計算する()
    {
        $fixedAsset = FixedAsset::factory()->create([
            'acquisition_date' => '2025-03-15',
            'taxable_amount' => 600_000,
            'tax_amount' => 0,
            'useful_life' => 60,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
        ]);

        $schedule = app(DepreciationService::class)
            ->calculateDepreciationScheduleUntilFullyDepreciated($fixedAsset);

        $this->assertSame([2025, 2026, 2027, 2028, 2029, 2030], array_keys($schedule));
        $this->assertSame(10, $schedule[2025]['months']);
        $this->assertSame(100_000, $schedule[2025]['ordinary_amount']);
        $this->assertSame(12, $schedule[2026]['months']);
        $this->assertSame(120_000, $schedule[2026]['ordinary_amount']);
        $this->assertSame(2, $schedule[2030]['months']);
        $this->assertSame(20_000, $schedule[2030]['ordinary_amount']);
        $this->assertSame(0, $schedule[2030]['ending_balance']);
    }

    #[Test]
    public function 取得日が月初でも月末でも初年度の月数は取得月から年末までの月数になる()
    {
        $expectedMonthsByAcquisitionDate = [
            '2025-01-01' => 12,
            '2025-03-01' => 10,
            '2025-03-31' => 10,
            '2025-12-31' => 1,
        ];

        foreach ($expectedMonthsByAcquisitionDate as $acquisitionDate => $expectedMonths) {
            $fixedAsset = FixedAsset::factory()->create([
                'acquisition_date' => $acquisitionDate,
                'taxable_amount' => 600_000,
                'tax_amount' => 0,
                'useful_life' => 60,
                'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
            ]);

            $schedule = app(DepreciationService::class)
                ->calculateDepreciationScheduleUntilFullyDepreciated($fixedAsset);

            $this->assertSame(
                $expectedMonths,
                $schedule[2025]['months'],
                "acquisition_date: {$acquisitionDate}",
            );
        }
    }

    #[Test]
    public function register_transaction_forは未記帳entryから減価償却仕訳を作成する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

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

        app(DepreciationService::class)->registerTransactionFor($entry, $user);

        $entry->refresh();

        $this->assertNotNull($entry->transaction_id);
        $this->assertFalse($entry->isUnposted());

        $transaction = Transaction::findOrFail($entry->transaction_id);

        $this->assertSame($fiscalYear2025->id, $transaction->fiscal_year_id);
        $this->assertSame('2025-12-31', $transaction->date->toDateString());
        $this->assertSame('2025年 減価償却: 工作機械', $transaction->description);
        $this->assertTrue($transaction->is_adjusting_entry);
        $this->assertSame(Transaction::ADJUSTING_ENTRY_TYPE_DEPRECIATION, $transaction->adjusting_entry_type);
        $this->assertCount(2, $transaction->journalEntries);

        $this->assertTrue(
            $transaction->journalEntries->contains(
                fn (JournalEntry $journalEntry) => $journalEntry->type === JournalEntry::TYPE_DEBIT
                    && $journalEntry->sub_account_id === $expenseSubAccount->id
                    && $journalEntry->net_amount === $entry->deductible_amount
                    && $journalEntry->tax_type === JournalEntry::TAX_TYPE_OUT_OF_SCOPE
            )
        );

        $this->assertTrue(
            $transaction->journalEntries->contains(
                fn (JournalEntry $journalEntry) => $journalEntry->type === JournalEntry::TYPE_CREDIT
                    && $journalEntry->sub_account_id === $assetSubAccount->id
                    && $journalEntry->net_amount === $entry->deductible_amount
                    && $journalEntry->tax_type === JournalEntry::TAX_TYPE_OUT_OF_SCOPE
            )
        );
    }

    #[Test]
    public function register_transaction_forは他ユーザーのentryを記帳できない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

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
                'name' => '認可テスト資産',
                'asset_category' => 'machinery',
                'acquisition_date' => '2025-01-01',
                'taxable_amount' => 120_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 60,
            ],
            ['date' => '2025-01-01', 'description' => '認可テスト資産購入'],
        );

        $entry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->firstOrFail();
        $otherUser = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この減価償却明細を記帳する権限がありません。');

        app(DepreciationService::class)->registerTransactionFor($entry, $otherUser);
    }

    #[Test]
    public function register_transaction_forは記帳済みentryを再記帳できない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

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

        $service->registerTransactionFor($entry, $user);

        $this->expectException(\InvalidArgumentException::class);

        $service->registerTransactionFor($entry->fresh(), $user);
    }

    #[Test]
    public function register_initial_opening_transferは_過年度取得の資産に期首振替仕訳を作成する_2022取得2023登録()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        // schedule: 2022 は 2ヶ月, ordinary = 6313499 * 0.167 * 2/12 = 175726 (round half up)
        // 2022 ending_balance = 6313499 - 175726 = 6137773
        $expectedOpeningBalance = 6_137_773;

        $transaction = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );

        $this->assertNotNull($transaction);
        $this->assertSame($fiscalYear2023->id, $transaction->fiscal_year_id);
        $this->assertTrue($transaction->is_opening_entry);
        $this->assertFalse((bool) $transaction->is_adjusting_entry);
        $this->assertSame('2023-01-01', $transaction->date->toDateString());
        $this->assertSame('期首残高設定', $transaction->description);

        $this->assertCount(2, $transaction->journalEntries);

        $debit = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $credit = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);
        $this->assertSame($expectedOpeningBalance, (int) $debit->net_amount);
        $this->assertSame($expectedOpeningBalance, (int) $credit->net_amount);
        $this->assertSame('車両運搬具', $debit->subAccount->account->name);
        $this->assertSame('元入金', $credit->subAccount->account->name);

        $this->assertSame($transaction->id, $fixedAsset->fresh()->initial_opening_transaction_id);
    }

    #[Test]
    public function register_initial_opening_transferは途中の_fiscal_yearが存在しなくても正しい期首簿価で作成する_2020取得2023登録()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewLightCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => '2020取得N-BOX',
                'acquisition_date' => '2020-04-15',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 120_000,
            ],
            ['date' => '2020-04-15', 'description' => '2020取得N-BOX取得'],
            true,
        );

        // 軽自動車: 耐用年数 48ヶ月, 償却率 = ceil(12/48*1000)/1000 = 0.250
        // 年額 = 1_320_000 * 0.250 = 330_000
        // 2020: 9ヶ月 (4月〜12月) → 330_000 * 9/12 = 247_500, ending = 1_072_500
        // 2021: 12ヶ月 → 330_000, ending = 742_500
        // 2022: 12ヶ月 → 330_000, ending = 412_500
        $expectedOpeningBalance = 412_500;

        $transaction = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );

        $debit = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $credit = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);
        $this->assertSame($expectedOpeningBalance, (int) $debit->net_amount);
        $this->assertSame($expectedOpeningBalance, (int) $credit->net_amount);
        $this->assertSame('車両運搬具', $debit->subAccount->account->name);
        $this->assertSame('元入金', $credit->subAccount->account->name);

        // 途中年度 (2020,2021,2022) の FiscalYear が DB に無いことも改めて確認
        $this->assertFalse($unit->fiscalYears()->where('year', 2020)->exists());
        $this->assertFalse($unit->fiscalYears()->where('year', 2021)->exists());
        $this->assertFalse($unit->fiscalYears()->where('year', 2022)->exists());
    }

    #[Test]
    public function register_initial_opening_transferは取得日が当年度内なら例外()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => '当年取得車',
                'acquisition_date' => '2023-05-01',
                'taxable_amount' => 3_000_000,
                'tax_amount' => 300_000,
            ],
            ['date' => '2023-05-01', 'description' => '当年取得車購入'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('過年度取得の固定資産にのみ期首振替を作成できます。');

        app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );
    }

    #[Test]
    public function register_initial_opening_transferは二重登録を例外で拒否する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $service = app(DepreciationService::class);
        $service->registerInitialOpeningTransfer($fixedAsset, $fiscalYear2023, $user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('この固定資産の期首振替は既に登録されています。');

        $service->registerInitialOpeningTransfer($fixedAsset->fresh(), $fiscalYear2023, $user);
    }

    #[Test]
    public function register_initial_opening_transferは他ユーザーからの呼び出しを拒否する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $otherUser = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $otherUser,
        );
    }

    #[Test]
    public function register_initial_opening_transferはactorがnullなら_authorization_exception()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $this->expectException(AuthorizationException::class);

        app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            null,
        );
    }

    #[Test]
    public function register_initial_opening_transferは期首簿価0の完全償却済み資産で例外()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        // 軽自動車 (48ヶ月) を 2018-01-01 に取得 → 2021 末で償却終了。
        // 2022 は schedule に存在せず、期首簿価は 0 になる。
        $fixedAsset = app(DepreciationService::class)->registerNewLightCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => '古い軽',
                'acquisition_date' => '2018-01-01',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 0,
            ],
            ['date' => '2018-01-01', 'description' => '古い軽取得'],
            true,
        );

        $this->assertSame(
            0,
            app(DepreciationService::class)->calculateOpeningBalanceFor($fixedAsset, $fiscalYear2023),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('期首簿価が 0 以下のため期首振替は作成できません。');

        app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );
    }

    #[Test]
    public function active_initial_opening_transactionはrevision_chainを辿って現行の_activeを返す()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $service = app(DepreciationService::class);

        // 1件目登録 → 新規 opening entry (T1)
        $assetA = $service->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $t1 = $service->registerInitialOpeningTransfer($assetA, $fiscalYear2023, $user);

        // 2件目登録 → 既存 opening entry を revise (T2)
        $assetB = $service->registerNewLightCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'N-BOX',
                'acquisition_date' => '2022-01-01',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 120_000,
            ],
            ['date' => '2022-01-01', 'description' => 'N-BOX取得'],
            true,
        );

        $t2 = $service->registerInitialOpeningTransfer($assetB, $fiscalYear2023, $user);

        $this->assertNotSame($t1->id, $t2->id);

        // T1 は deactivated、revision は T2
        $this->assertFalse($t1->fresh()->is_active);
        $this->assertTrue($t2->fresh()->is_active);

        // 1件目資産の FK は T1 を指したまま
        $assetA->refresh();
        $this->assertSame($t1->id, $assetA->initial_opening_transaction_id);

        // active accessor は revision chain を辿って T2 を返す
        $active = $assetA->activeInitialOpeningTransaction();
        $this->assertNotNull($active);
        $this->assertSame($t2->id, $active->id);
        $this->assertTrue($active->is_active);

        // 2件目資産は FK 自体が T2
        $assetB->refresh();
        $this->assertSame($t2->id, $assetB->initial_opening_transaction_id);
    }

    #[Test]
    public function 期首振替_transactionを削除すると_fixed_assetの_fkが_nullにされる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $transaction = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );

        $this->assertSame($transaction->id, $fixedAsset->fresh()->initial_opening_transaction_id);

        $transaction->delete();

        $this->assertNull($fixedAsset->fresh()->initial_opening_transaction_id);

        // FK がクリアされたので、再度期首振替を作成できる
        $newTransaction = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset->fresh(),
            $fiscalYear2023,
            $user,
        );
        $this->assertNotSame($transaction->id, $newTransaction->id);
    }

    #[Test]
    public function register_initial_opening_transferは既存opening_entryが無ければ新規作成()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $this->assertSame(
            0,
            $fiscalYear2023->transactions()->where('is_opening_entry', true)->count(),
        );

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $transaction = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );

        $this->assertSame(
            1,
            $fiscalYear2023->transactions()->where('is_opening_entry', true)->where('is_active', true)->count(),
        );
        $this->assertSame('期首残高設定', $transaction->description);
    }

    #[Test]
    public function register_initial_opening_transferは既存opening_entryに車両運搬具行が無ければ_行追加でrevise()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        // 既存の opening entry を「現金 1_000_000 / 元入金 1_000_000」で用意
        $fiscalYear2023->registerOpeningEntry(
            [
                ['account_name' => '現金', 'sub_account_name' => '現金', 'amount' => 1_000_000],
            ],
            $user,
        );

        $originalOpeningEntry = $fiscalYear2023->transactions()
            ->where('is_opening_entry', true)
            ->where('is_active', true)
            ->firstOrFail();
        $originalId = $originalOpeningEntry->id;

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $expectedOpeningBalance = 6_137_773;

        $revised = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );

        // 元の opening entry は deactivated
        $this->assertNotSame($originalId, $revised->id);
        $this->assertFalse(Transaction::find($originalId)->is_active);
        $this->assertTrue($revised->is_active);

        // active opening entry は 1 本のまま
        $this->assertSame(
            1,
            $fiscalYear2023->transactions()->where('is_opening_entry', true)->where('is_active', true)->count(),
        );

        // 借方 2 行 (現金 保持 + 車両運搬具 新規), 貸方 1 行 (元入金 合計)
        $revised->load('journalEntries.subAccount.account');
        $debits = $revised->journalEntries->where('type', JournalEntry::TYPE_DEBIT)->values();
        $credits = $revised->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->values();

        $this->assertCount(2, $debits);
        $this->assertCount(1, $credits);

        $accountNames = $debits->map(fn ($e) => $e->subAccount->account->name)->all();
        $this->assertContains('現金', $accountNames);
        $this->assertContains('車両運搬具', $accountNames);

        $cashAmount = $debits->firstWhere(fn ($e) => $e->subAccount->account->name === '現金')->net_amount;
        $vehicleAmount = $debits->firstWhere(fn ($e) => $e->subAccount->account->name === '車両運搬具')->net_amount;
        $this->assertSame(1_000_000, (int) $cashAmount);
        $this->assertSame($expectedOpeningBalance, (int) $vehicleAmount);

        $creditAmount = (int) $credits->first()->net_amount;
        $this->assertSame(1_000_000 + $expectedOpeningBalance, $creditAmount);
        $this->assertSame('元入金', $credits->first()->subAccount->account->name);
    }

    #[Test]
    public function register_initial_opening_transferは既存opening_entryに同じ車両運搬具行があっても_additiveで別行を追加()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        // 既存の opening entry に「車両運搬具 3_000_000」の debit がある状態を作る
        $fiscalYear2023->registerOpeningEntry(
            [
                ['account_name' => '車両運搬具', 'sub_account_name' => '車両運搬具', 'amount' => 3_000_000],
            ],
            $user,
        );

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $expectedOpeningBalance = 6_137_773;

        $revised = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );

        $revised->load('journalEntries.subAccount.account');
        $vehicleDebits = $revised->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->filter(fn ($e) => $e->subAccount->account->name === '車両運搬具')
            ->values();

        // 車両運搬具 の debit 行は 2 行 (既存 3_000_000 + 新規 期首簿価)
        $this->assertCount(2, $vehicleDebits);
        $amounts = $vehicleDebits->map(fn ($e) => (int) $e->net_amount)->sort()->values()->all();
        $this->assertSame([3_000_000, $expectedOpeningBalance], $amounts);

        // 貸方は total
        $credit = $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);
        $this->assertSame(3_000_000 + $expectedOpeningBalance, (int) $credit->net_amount);
    }

    #[Test]
    public function 複数固定資産を登録すると1つのopening_entryに複数debit行が並ぶ_同じ車両運搬具()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $service = app(DepreciationService::class);

        $assetA = $service->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );
        $service->registerInitialOpeningTransfer($assetA, $fiscalYear2023, $user);

        $assetB = $service->registerNewLightCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'N-BOX',
                'acquisition_date' => '2022-01-01',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 120_000,
            ],
            ['date' => '2022-01-01', 'description' => 'N-BOX取得'],
            true,
        );
        $service->registerInitialOpeningTransfer($assetB, $fiscalYear2023, $user);

        // active な opening entry は 1 本
        $this->assertSame(
            1,
            $fiscalYear2023->transactions()->where('is_opening_entry', true)->where('is_active', true)->count(),
        );

        $activeOpening = $fiscalYear2023->transactions()
            ->with('journalEntries.subAccount.account')
            ->where('is_opening_entry', true)
            ->where('is_active', true)
            ->firstOrFail();

        $vehicleDebits = $activeOpening->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->filter(fn ($e) => $e->subAccount->account->name === '車両運搬具')
            ->values();

        $this->assertCount(2, $vehicleDebits);

        // Tesla 期首簿価 = 6_137_773, N-BOX (48ヶ月, 2022年 12ヶ月償却) = 990_000
        // N-BOX: rate = 0.250, annual = 330_000, 2022 ending = 990_000
        $expectedNBoxOpening = 990_000;
        $amounts = $vehicleDebits->map(fn ($e) => (int) $e->net_amount)->sort()->values()->all();
        $this->assertSame([$expectedNBoxOpening, 6_137_773], $amounts);
    }

    #[Test]
    public function 異なるsub_accountの固定資産を登録するとsub_accountごとにdebit行が並ぶ()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $vehicleSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', '車両運搬具'))
            ->firstOrFail();
        $machinerySubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', '機械装置'))
            ->firstOrFail();
        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $service = app(DepreciationService::class);

        $car = $service->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );
        $service->registerInitialOpeningTransfer($car, $fiscalYear2023, $user);

        $machine = $service->registerFixedAsset(
            $fiscalYear2023,
            $machinerySubAccount,
            $paymentSubAccount,
            [
                'name' => '旋盤',
                'asset_category' => 'machinery',
                'acquisition_date' => '2022-01-01',
                'taxable_amount' => 500_000,
                'tax_amount' => 0,
                'useful_life' => 120,
                'depreciation_method' => 'straight_line',
            ],
            ['date' => '2022-01-01', 'description' => '旋盤取得'],
            true,
        );
        $service->registerInitialOpeningTransfer($machine, $fiscalYear2023, $user);

        $activeOpening = $fiscalYear2023->transactions()
            ->with('journalEntries.subAccount.account')
            ->where('is_opening_entry', true)
            ->where('is_active', true)
            ->firstOrFail();

        $debits = $activeOpening->journalEntries->where('type', JournalEntry::TYPE_DEBIT)->values();
        $accountNames = $debits->map(fn ($e) => $e->subAccount->account->name)->sort()->values()->all();

        $this->assertContains('車両運搬具', $accountNames);
        $this->assertContains('機械装置', $accountNames);
    }

    #[Test]
    public function 既存opening_entryの貸方が非元入金のみでも_資産分は元入金の新規行として追加される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        // 現金 500_000 debit + 借入金 500_000 credit (元入金は含まない) の期首仕訳を手動で作成
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');
        $loanSubAccount = $unit->accounts()->where('name', '借入金')->firstOrFail()
            ->subAccounts()->firstOrCreate(['name' => '借入金']);

        app(TransactionRegistrar::class)->register(
            $fiscalYear2023,
            [
                'date' => $fiscalYear2023->start_date->toDateString(),
                'description' => '期首残高設定',
                'is_opening_entry' => true,
            ],
            [
                ['sub_account_id' => $cashSubAccount->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 500_000],
                ['sub_account_id' => $loanSubAccount->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 500_000],
            ],
            $user,
        );

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $expectedOpeningBalance = 6_137_773;

        $revised = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );

        $revised->load('journalEntries.subAccount.account');
        $credits = $revised->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->values();

        // 借入金 credit は据え置き、元入金 credit が新規に追加される
        $this->assertCount(2, $credits);

        $loanCredit = $credits->firstWhere(fn ($e) => $e->subAccount->account->name === '借入金');
        $capitalCredit = $credits->firstWhere(fn ($e) => $e->subAccount->account->name === '元入金');

        $this->assertNotNull($loanCredit, '借入金 credit は保持される');
        $this->assertNotNull($capitalCredit, '元入金 credit が資産分として新規追加される');
        $this->assertSame(500_000, (int) $loanCredit->net_amount, '借入金 は勝手に増額されない');
        $this->assertSame($expectedOpeningBalance, (int) $capitalCredit->net_amount);

        // 借方の合計と貸方の合計が一致
        $totalDebit = (int) $revised->journalEntries->where('type', JournalEntry::TYPE_DEBIT)->sum('net_amount');
        $totalCredit = (int) $revised->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->sum('net_amount');
        $this->assertSame($totalDebit, $totalCredit);
    }

    #[Test]
    public function ロールオーバー型_資産と負債と元入金_の期首仕訳にも固定資産を追加できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        // rollover 相当の期首仕訳を手動で作成:
        //   借方: 現金 1_000_000
        //   貸方: 借入金 300_000, 元入金 700_000
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');
        $capitalSubAccount = $unit->getSubAccountByName('元入金', '元入金');
        $loanSubAccount = $unit->accounts()->where('name', '借入金')->firstOrFail()
            ->subAccounts()->firstOrCreate(['name' => '借入金']);

        app(TransactionRegistrar::class)->register(
            $fiscalYear2023,
            [
                'date' => $fiscalYear2023->start_date->toDateString(),
                'description' => '期首残高設定',
                'is_opening_entry' => true,
            ],
            [
                ['sub_account_id' => $cashSubAccount->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 1_000_000],
                ['sub_account_id' => $loanSubAccount->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 300_000],
                ['sub_account_id' => $capitalSubAccount->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 700_000],
            ],
            $user,
        );

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $expectedOpeningBalance = 6_137_773;

        $revised = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );

        $revised->load('journalEntries.subAccount.account');

        $debits = $revised->journalEntries->where('type', JournalEntry::TYPE_DEBIT)->values();
        $credits = $revised->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->values();

        // 現金 debit と 車両運搬具 debit の 2 行
        $this->assertCount(2, $debits);
        $cashDebit = $debits->firstWhere(fn ($e) => $e->subAccount->account->name === '現金');
        $vehicleDebit = $debits->firstWhere(fn ($e) => $e->subAccount->account->name === '車両運搬具');
        $this->assertSame(1_000_000, (int) $cashDebit->net_amount);
        $this->assertSame($expectedOpeningBalance, (int) $vehicleDebit->net_amount);

        // 借入金 は据え置き、元入金 は差引で再計算される
        $this->assertCount(2, $credits);
        $loanCredit = $credits->firstWhere(fn ($e) => $e->subAccount->account->name === '借入金');
        $capitalCredit = $credits->firstWhere(fn ($e) => $e->subAccount->account->name === '元入金');
        $this->assertSame(300_000, (int) $loanCredit->net_amount, '借入金 は勝手に増額されない');

        // 元入金 = (1_000_000 + 6_137_773) - 300_000 = 6_837_773
        $this->assertSame(1_000_000 + $expectedOpeningBalance - 300_000, (int) $capitalCredit->net_amount);
        // 元の 700_000 から資産分だけ増加していることを差分でも確認
        $this->assertSame($expectedOpeningBalance, (int) $capitalCredit->net_amount - 700_000 - (-300_000 + 300_000));

        // 借方 = 貸方
        $totalDebit = (int) $revised->journalEntries->where('type', JournalEntry::TYPE_DEBIT)->sum('net_amount');
        $totalCredit = (int) $revised->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->sum('net_amount');
        $this->assertSame($totalDebit, $totalCredit);
    }

    #[Test]
    public function 負債超過ケースでは元入金がdebit側に来る()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        // 現金 100_000 debit + 元入金 9_900_000 debit + 借入金 10_000_000 credit
        // (負債超過 = 元入金 debit)
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');
        $capitalSubAccount = $unit->getSubAccountByName('元入金', '元入金');
        $loanSubAccount = $unit->accounts()->where('name', '借入金')->firstOrFail()
            ->subAccounts()->firstOrCreate(['name' => '借入金']);

        app(TransactionRegistrar::class)->register(
            $fiscalYear2023,
            [
                'date' => $fiscalYear2023->start_date->toDateString(),
                'description' => '期首残高設定',
                'is_opening_entry' => true,
            ],
            [
                ['sub_account_id' => $cashSubAccount->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 100_000],
                ['sub_account_id' => $capitalSubAccount->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 9_900_000],
                ['sub_account_id' => $loanSubAccount->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 10_000_000],
            ],
            $user,
        );

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => 'Tesla',
                'acquisition_date' => '2022-11-20',
                'taxable_amount' => 5_682_150,
                'tax_amount' => 631_349,
            ],
            ['date' => '2022-11-20', 'description' => 'Tesla取得'],
            true,
        );

        $expectedOpeningBalance = 6_137_773;

        $revised = app(DepreciationService::class)->registerInitialOpeningTransfer(
            $fixedAsset,
            $fiscalYear2023,
            $user,
        );

        $revised->load('journalEntries.subAccount.account');

        // 元入金は debit 側に残るはず
        // 元入金以外の debit = 100_000 + 6_137_773 = 6_237_773
        // credit = 10_000_000 (借入金)
        // capital = 6_237_773 - 10_000_000 = -3_762_227 → 元入金 debit 3_762_227
        $capitalRows = $revised->journalEntries
            ->filter(fn ($e) => $e->subAccount->account->name === '元入金')
            ->values();

        $this->assertCount(1, $capitalRows);
        $capital = $capitalRows->first();
        $this->assertSame(JournalEntry::TYPE_DEBIT, $capital->type);
        $this->assertSame(10_000_000 - $expectedOpeningBalance - 100_000, (int) $capital->net_amount);

        // 借入金 は据え置き
        $loanCredit = $revised->journalEntries
            ->firstWhere(fn ($e) => $e->subAccount->account->name === '借入金');
        $this->assertSame(10_000_000, (int) $loanCredit->net_amount);

        // 借方 = 貸方
        $totalDebit = (int) $revised->journalEntries->where('type', JournalEntry::TYPE_DEBIT)->sum('net_amount');
        $totalCredit = (int) $revised->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->sum('net_amount');
        $this->assertSame($totalDebit, $totalCredit);
    }

    #[Test]
    public function needs_initial_opening_transferは過年度取得かつ未登録のときのみtrue()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear2023 = $unit->createFiscalYear(2023, $user);

        $paymentSubAccount = $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
            ->firstOrFail();

        $currentYearAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => '当年取得車',
                'acquisition_date' => '2023-05-01',
                'taxable_amount' => 3_000_000,
                'tax_amount' => 300_000,
            ],
            ['date' => '2023-05-01', 'description' => '当年取得車購入'],
        );

        $pastAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear2023,
            $paymentSubAccount,
            [
                'name' => '過年取得車',
                'acquisition_date' => '2022-05-01',
                'taxable_amount' => 3_000_000,
                'tax_amount' => 300_000,
            ],
            ['date' => '2022-05-01', 'description' => '過年取得車取得'],
            true,
        );

        $this->assertFalse($currentYearAsset->needsInitialOpeningTransfer($fiscalYear2023));
        $this->assertTrue($pastAsset->needsInitialOpeningTransfer($fiscalYear2023));

        app(DepreciationService::class)->registerInitialOpeningTransfer(
            $pastAsset,
            $fiscalYear2023,
            $user,
        );

        $this->assertFalse($pastAsset->fresh()->needsInitialOpeningTransfer($fiscalYear2023));
    }

    // 後回し
    //     #[Test]
    //     public function 固定資産を登録すると取得仕訳も同時に登録される_課税事業者_税別仕訳()
    //     {
    //         $user = User::factory()->create();
    //         $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
    //         $fiscalYear = $unit->createFiscalYear(2023, $user);

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
