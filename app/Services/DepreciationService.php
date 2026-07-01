<?php

namespace App\Services;

use App\Models\DepreciationEntry;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DepreciationService
{
    public function __construct(
        private readonly TransactionRegistrar $transactionRegistrar
    ) {}

    private const NEW_STANDARD_CAR_PRESET = [
        'asset_category' => '新車-普通車',
        'useful_life' => 72,
        'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
    ];

    private const NEW_LIGHT_CAR_PRESET = [
        'asset_category' => '新車-軽自動車',
        'useful_life' => 48,
        'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
    ];

    public function registerNewStandardCar(
        FiscalYear $fiscalYear,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData,
        bool $allowRegistration = false
    ): FixedAsset {
        $assetSubAccount = $this->resolveVehicleAssetSubAccount($fiscalYear);

        return $this->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            array_merge($fixedAssetData, self::NEW_STANDARD_CAR_PRESET),
            $transactionData,
            $allowRegistration,
        );
    }

    public function registerNewLightCar(
        FiscalYear $fiscalYear,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData,
        bool $allowRegistration = false
    ): FixedAsset {
        $assetSubAccount = $this->resolveVehicleAssetSubAccount($fiscalYear);

        return $this->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            array_merge($fixedAssetData, self::NEW_LIGHT_CAR_PRESET),
            $transactionData,
            $allowRegistration,
        );
    }

    public function registerFixedAsset(
        FiscalYear $fiscalYear,
        SubAccount $assetSubAccount,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData,
        bool $allowRegistration = false
    ): FixedAsset {
        $acquisitionDate = $fixedAssetData['acquisition_date'];

        if (
            ! $allowRegistration
            && Carbon::parse($acquisitionDate)->lt(Carbon::parse($fiscalYear->start_date))
        ) {
            throw new \InvalidArgumentException('過去に取得した固定資産は allowRegistration を true にしないと登録できません。');
        }

        return DB::transaction(function () use (
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            $fixedAssetData,
            $transactionData
        ) {
            $acquisitionDate = $fixedAssetData['acquisition_date'];
            $businessUnit = $fiscalYear->businessUnit;

            // 2. 金額計算
            $taxableAmount = $fixedAssetData['taxable_amount'];
            $taxAmount = $fixedAssetData['tax_amount'] ?? 0;

            // 3. 固定資産登録
            $asset = FixedAsset::create([
                'business_unit_id' => $businessUnit->id,
                'account_id' => $assetSubAccount->account_id,
                'name' => $fixedAssetData['name'],
                'asset_category' => $fixedAssetData['asset_category'],
                'acquisition_date' => $acquisitionDate,
                'taxable_amount' => $taxableAmount,
                'tax_amount' => $taxAmount,
                'useful_life' => $fixedAssetData['useful_life'],
                'depreciation_method' => $fixedAssetData['depreciation_method'],
            ]);

            // 4. 取得仕訳の登録（取得日が今年度内の場合のみ）
            if (
                Carbon::parse($acquisitionDate)->betweenIncluded(
                    $fiscalYear->start_date,
                    $fiscalYear->end_date
                )
            ) {
                $transaction = Transaction::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'date' => $transactionData['date'],
                    'description' => $transactionData['description'],
                ]);

                $transaction->journalEntries()->createMany([
                    [
                        'sub_account_id' => $assetSubAccount->id,
                        'type' => 'debit',
                        'net_amount' => $asset->acquisition_cost,
                    ],
                    [
                        'sub_account_id' => $paymentSubAccount->id,
                        'type' => 'credit',
                        'net_amount' => $asset->acquisition_cost,
                    ],
                ]);
            }

            $this->createDepreciationEntriesUpTo($fiscalYear, $asset, $acquisitionDate, $fixedAssetData);

            return $asset;
        });
    }

    /**
     * 取得年度から $upToFiscalYear までの全年度分の DepreciationEntry を作成する。
     * 過去の年度を遡って登録する場合に、途中年度が DB に存在しないことがあるため、
     * 存在する年度分のみ作成する。
     */
    private function createDepreciationEntriesUpTo(
        FiscalYear $upToFiscalYear,
        FixedAsset $asset,
        string $acquisitionDate,
        array $fixedAssetData
    ): void {
        $businessUsageRatio = (float) ($fixedAssetData['business_usage_ratio'] ?? 1.00);

        $fullSchedule = $this->calculateDepreciationScheduleUntilFullyDepreciated($asset);
        $acquisitionYear = (int) Carbon::parse($acquisitionDate)->format('Y');

        foreach ($fullSchedule as $year => $values) {
            if ($year > $upToFiscalYear->year) {
                break;
            }

            if ($year < $acquisitionYear) {
                continue;
            }

            $fiscalYear = $upToFiscalYear->businessUnit->fiscalYears()
                ->where('year', $year)
                ->first();

            if ($fiscalYear === null) {
                continue;
            }

            DepreciationEntry::updateOrCreate(
                [
                    'fiscal_year_id' => $fiscalYear->id,
                    'fixed_asset_id' => $asset->id,
                ],
                $values + [
                    'business_usage_ratio' => $businessUsageRatio,
                    'deductible_amount' => (int) floor($values['total_amount'] * $businessUsageRatio),
                    'transaction_id' => null,
                ],
            );
        }
    }

    /**
     * 取得年度から耐用年数を使い切るまでの減価償却予定を年別に返す。
     *
     * @return array<int, array{months: int, ordinary_amount: int, special_amount: int, total_amount: int, ending_balance: int}>
     */
    public function calculateDepreciationScheduleUntilFullyDepreciated(
        FixedAsset $asset
    ): array {
        $acquisitionDate = $asset->acquisition_date?->toDateString();

        if ($acquisitionDate === null) {
            return [];
        }

        $acquisitionYear = (int) Carbon::parse($acquisitionDate)->format('Y');
        $remainingAmount = (int) $asset->acquisition_cost;
        $remainingMonths = max(0, (int) $asset->useful_life);
        $depreciationRate = $this->calculateDepreciationRate($asset);
        $annualAmount = $depreciationRate === null
            ? 0
            : (int) round($remainingAmount * $depreciationRate, 0, PHP_ROUND_HALF_UP);

        $schedule = [];

        for ($year = $acquisitionYear; $remainingMonths > 0 && $remainingAmount > 0; $year++) {
            $months = $this->calculateDepreciationMonthsForCalendarYear(
                $year,
                $acquisitionDate,
                $remainingMonths,
            );

            if ($months === 0) {
                continue;
            }

            $ordinaryAmount = (int) round($annualAmount * ($months / 12), 0, PHP_ROUND_HALF_UP);

            if ($ordinaryAmount > $remainingAmount) {
                $ordinaryAmount = $remainingAmount;
            }

            $totalAmount = $ordinaryAmount;
            $remainingAmount -= $ordinaryAmount;
            $remainingMonths -= $months;

            $schedule[$year] = [
                'months' => $months,
                'ordinary_amount' => $ordinaryAmount,
                'special_amount' => 0,
                'total_amount' => $totalAmount,
                'ending_balance' => max(0, $remainingAmount),
            ];
        }

        return $schedule;
    }

    public function isFullyDepreciated(FixedAsset $asset, FiscalYear $fiscalYear): bool
    {
        return ! array_key_exists($fiscalYear->year, $this->calculateDepreciationScheduleUntilFullyDepreciated($asset));
    }

    public function isStillDepreciating(FixedAsset $asset, FiscalYear $fiscalYear): bool
    {
        return ! $this->isFullyDepreciated($asset, $fiscalYear);
    }

    private function calculateDepreciationRate(FixedAsset $asset): ?float
    {
        $usefulLife = (int) $asset->useful_life;

        if ($usefulLife <= 0) {
            return null;
        }

        return ceil((12 / $usefulLife) * 1000) / 1000;
    }

    private function resolveVehicleAssetSubAccount(FiscalYear $fiscalYear): SubAccount
    {
        $subAccount = $fiscalYear->businessUnit->getSubAccountByName('車両運搬具', '車両運搬具');

        if ($subAccount === null) {
            throw new \RuntimeException('車両運搬具の補助科目が見つかりません。');
        }

        return $subAccount;
    }

    private function calculateDepreciationMonthsForFiscalYear(FiscalYear $fiscalYear, string $acquisitionDate): int
    {
        $fiscalStart = Carbon::parse($fiscalYear->start_date)->startOfMonth();
        $fiscalEnd = Carbon::parse($fiscalYear->end_date)->endOfMonth();
        $acquisitionMonth = Carbon::parse($acquisitionDate)->startOfMonth();

        $depreciationStart = $acquisitionMonth->greaterThan($fiscalStart)
            ? $acquisitionMonth
            : $fiscalStart;

        if ($depreciationStart->greaterThan($fiscalEnd)) {
            return 0;
        }

        return $depreciationStart->diffInMonths($fiscalEnd) + 1;
    }

    private function calculateDepreciationMonthsForCalendarYear(
        int $year,
        string $acquisitionDate,
        int $remainingMonths
    ): int {
        $fiscalStart = Carbon::create($year, 1, 1)->startOfMonth();
        $fiscalEnd = Carbon::create($year, 12, 31)->endOfMonth();
        $acquisitionMonth = Carbon::parse($acquisitionDate)->startOfMonth();

        $depreciationStart = $acquisitionMonth->greaterThan($fiscalStart)
            ? $acquisitionMonth
            : $fiscalStart;

        if ($depreciationStart->greaterThan($fiscalEnd)) {
            return 0;
        }

        return min($depreciationStart->diffInMonths($fiscalEnd) + 1, $remainingMonths);
    }

    public function prepareEntriesFor(FiscalYear $fiscalYear): void
    {
        $businessUnit = $fiscalYear->businessUnit;

        $fixedAssets = $businessUnit->fixedAssets()
            ->with(['depreciationEntries' => function ($query): void {
                $query->orderBy('fiscal_year_id');
            }])
            ->orderBy('acquisition_date')
            ->orderBy('id')
            ->get();

        foreach ($fixedAssets as $fixedAsset) {
            $schedule = $this->calculateDepreciationScheduleUntilFullyDepreciated($fixedAsset);
            $values = $schedule[$fiscalYear->year] ?? null;

            if ($values === null) {
                continue;
            }

            $businessUsageRatio = $this->resolveBusinessUsageRatio($fixedAsset);

            $fixedAsset->depreciationEntries()->firstOrCreate(
                [
                    'fiscal_year_id' => $fiscalYear->id,
                ],
                $values + [
                    'business_usage_ratio' => $businessUsageRatio,
                    'deductible_amount' => (int) floor($values['total_amount'] * $businessUsageRatio),
                    'transaction_id' => null,
                ],
            );
        }
    }

    public function registerTransactionFor(DepreciationEntry $entry): void
    {
        $entry->loadMissing('fiscalYear.businessUnit', 'fixedAsset.account');

        if (! $entry->isUnposted()) {
            throw new \InvalidArgumentException('この減価償却明細は既に記帳済みです。');
        }

        $expenseSubAccount = $this->resolveDepreciationExpenseSubAccount($entry->fiscalYear);
        $assetSubAccount = $this->resolveFixedAssetSubAccount($entry->fiscalYear, $entry->fixedAsset);

        $transaction = $this->transactionRegistrar->register(
            $entry->fiscalYear,
            [
                'date' => $entry->fiscalYear->end_date->toDateString(),
                'description' => sprintf('%d年 減価償却: %s', $entry->fiscalYear->year, $entry->fixedAsset->name),
                'is_adjusting_entry' => true,
            ],
            [
                [
                    'sub_account_id' => $expenseSubAccount->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => $entry->deductible_amount,
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                    'tax_amount' => 0,
                ],
                [
                    'sub_account_id' => $assetSubAccount->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => $entry->deductible_amount,
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                    'tax_amount' => 0,
                ],
            ],
        );

        $entry->forceFill([
            'transaction_id' => $transaction->id,
        ])->save();
    }

    private function resolveBusinessUsageRatio(FixedAsset $asset): float
    {
        $businessUsageRatio = $asset->depreciationEntries()
            ->orderBy('fiscal_year_id')
            ->value('business_usage_ratio');

        if ($businessUsageRatio === null) {
            return 1.00;
        }

        return (float) $businessUsageRatio;
    }

    private function resolveDepreciationExpenseSubAccount(FiscalYear $fiscalYear): SubAccount
    {
        $subAccount = $fiscalYear->businessUnit->getSubAccountByName('減価償却費', '減価償却費');

        if ($subAccount === null) {
            throw new \RuntimeException('減価償却費の補助科目が見つかりません。');
        }

        return $subAccount;
    }

    private function resolveFixedAssetSubAccount(FiscalYear $fiscalYear, FixedAsset $asset): SubAccount
    {
        $accountName = $asset->account->name;
        $subAccount = $fiscalYear->businessUnit->getSubAccountByName($accountName, $accountName);

        if ($subAccount === null) {
            throw new \RuntimeException("{$accountName} の補助科目が見つかりません。");
        }

        return $subAccount;
    }
}
