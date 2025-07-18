<?php

namespace App\Services;

use App\Models\BusinessUnit;
use App\Models\Account;
use App\Models\SubAccount;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class DepreciationService
{

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
                    'amount' => $acquisitionCost,
                ],
                [
                    'sub_account_id' => $paymentSubAccount->id,
                    'type' => 'credit',
                    'amount' => $acquisitionCost,
                ],
            ]);

            return $asset;
        });
    }

    public function prepareEntriesFor(FiscalYear $fiscalYear): void
    {
        // 実装は後で
    }

    public function registerJournalEntryFor(\App\Models\DepreciationEntry $entry): void
    {
        // 実装は後で
    }

    public function registerAllUnregisteredEntriesFor(FiscalYear $fiscalYear): void
    {
        // 実装は後で
    }
}
