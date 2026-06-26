<?php

namespace App\Services;

use App\Models\DepreciationEntry;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\SubAccount;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DepreciationService
{
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
        array $transactionData
    ): FixedAsset {
        $assetSubAccount = $this->resolveVehicleAssetSubAccount($fiscalYear);

        return $this->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            array_merge($fixedAssetData, self::NEW_STANDARD_CAR_PRESET),
            $transactionData,
        );
    }

    public function registerNewLightCar(
        FiscalYear $fiscalYear,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData
    ): FixedAsset {
        $assetSubAccount = $this->resolveVehicleAssetSubAccount($fiscalYear);

        return $this->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            array_merge($fixedAssetData, self::NEW_LIGHT_CAR_PRESET),
            $transactionData,
        );
    }

    public function registerFixedAsset(
        FiscalYear $fiscalYear,
        SubAccount $assetSubAccount,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData
    ): FixedAsset {
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
            $acquisitionCost = $taxableAmount + $taxAmount;

            // 3. 減価償却の基礎金額( 課税業者の場合は税抜価格、免税業者の場合は税込価格 )
            $depreciationBaseAmount = $fiscalYear->is_taxable
                ? $taxableAmount
                : $acquisitionCost;

            // 4. 固定資産登録
            $asset = FixedAsset::create([
                'business_unit_id' => $businessUnit->id,
                'account_id' => $assetSubAccount->account_id,
                'name' => $fixedAssetData['name'],
                'asset_category' => $fixedAssetData['asset_category'],
                'acquisition_date' => $acquisitionDate,
                'taxable_amount' => $taxableAmount,
                'tax_amount' => $taxAmount,
                'acquisition_cost' => $acquisitionCost,
                'depreciation_base_amount' => $depreciationBaseAmount,
                'useful_life' => $fixedAssetData['useful_life'],
                'depreciation_method' => $fixedAssetData['depreciation_method'],
            ]);

            // 5. 取得仕訳の登録（2本仕訳：借方=資産, 貸方=支払）
            $transaction = Transaction::create([
                'fiscal_year_id' => $fiscalYear->id,
                'date' => $transactionData['date'],
                'description' => $transactionData['description'],
            ]);

            $transaction->journalEntries()->createMany([
                [
                    'sub_account_id' => $assetSubAccount->id,
                    'type' => 'debit',
                    'net_amount' => $acquisitionCost,
                ],
                [
                    'sub_account_id' => $paymentSubAccount->id,
                    'type' => 'credit',
                    'net_amount' => $acquisitionCost,
                ],
            ]);

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
        $acquisitionYear = (int) Carbon::parse($acquisitionDate)->format('Y');
        $businessUsageRatio = (float) ($fixedAssetData['business_usage_ratio'] ?? 1.00);

        $usefulLife = (int) $asset->useful_life;
        $ordinaryMonthlyAmount = $usefulLife > 0
            ? intdiv((int) $asset->depreciation_base_amount, $usefulLife)
            : 0;

        $fiscalYears = $upToFiscalYear->businessUnit->fiscalYears()
            ->whereBetween('year', [$acquisitionYear, $upToFiscalYear->year])
            ->orderBy('year')
            ->get();

        foreach ($fiscalYears as $fiscalYear) {
            $months = $this->calculateDepreciationMonthsForFiscalYear($fiscalYear, $acquisitionDate);
            $ordinaryAmount = $ordinaryMonthlyAmount * $months;
            $specialAmount = 0;
            $totalAmount = $ordinaryAmount + $specialAmount;
            $deductibleAmount = (int) floor($totalAmount * $businessUsageRatio);

            DepreciationEntry::updateOrCreate(
                [
                    'fiscal_year_id' => $fiscalYear->id,
                    'fixed_asset_id' => $asset->id,
                ],
                [
                    'months' => $months,
                    'ordinary_amount' => $ordinaryAmount,
                    'special_amount' => $specialAmount,
                    'total_amount' => $totalAmount,
                    'business_usage_ratio' => $businessUsageRatio,
                    'deductible_amount' => $deductibleAmount,
                    'journal_entry_id' => null,
                ]
            );
        }
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

    public function prepareEntriesFor(FiscalYear $fiscalYear): void
    {
        // 実装は後で
    }

    public function registerJournalEntryFor(DepreciationEntry $entry): void
    {
        // 実装は後で
    }

    public function registerAllUnregisteredEntriesFor(FiscalYear $fiscalYear): void
    {
        // 実装は後で
    }
}
