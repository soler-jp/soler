<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;

class FiscalYearRolloverDataCalculator
{
    /**
     * 翌期繰越に必要なデータを返す。
     *
     * 貸借対照表科目の期末残高と当期所得から、
     * 翌期の期首仕訳と元入金の調整額を返す。
     *
     * @return array{
     *     next_year: int,
     *     opening_entries: array<int, array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}>,
     *     capital_entry: array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'},
     *     current_profit: int
     * }
     */
    public function calculate(FiscalYear $fiscalYear): array
    {
        $balanceSummary = $fiscalYear->calculateBalanceSummary();
        $summary = $fiscalYear->calculateSummary();
        $currentProfit = (int) $summary['actual']['profit'];

        return [
            'next_year' => $fiscalYear->year + 1,
            'opening_entries' => $this->buildOpeningEntries($balanceSummary),
            'capital_entry' => $this->buildCapitalEntry($balanceSummary, $currentProfit),
            'current_profit' => $currentProfit,
        ];
    }

    /**
     * @param  array{
     *     asset: array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>},
     *     liability: array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>},
     *     equity: array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>}
     * }  $balanceSummary
     * @return array<int, array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}>
     */
    protected function buildOpeningEntries(array $balanceSummary): array
    {
        $openingEntries = [];

        foreach ([Account::TYPE_ASSET, Account::TYPE_LIABILITY] as $accountType) {
            foreach ($balanceSummary[$accountType]['accounts'] as $account) {
                foreach ($account['sub_accounts'] as $subAccount) {
                    $balance = (int) $subAccount['balance'];

                    if ($balance === 0) {
                        continue;
                    }

                    $openingEntries[] = [
                        'account_name' => $account['account_name'],
                        'sub_account_name' => $subAccount['sub_account_name'],
                        'amount' => abs($balance),
                        'type' => $this->balanceTypeToJournalEntryType($accountType, $balance),
                    ];
                }
            }
        }

        return $openingEntries;
    }

    /**
     * @param  array{
     *     asset: array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>},
     *     liability: array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>},
     *     equity: array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>}
     * }  $balanceSummary
     * @return array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}
     */
    protected function buildCapitalEntry(array $balanceSummary, int $currentProfit): array
    {
        $capitalBalance = (int) $balanceSummary[Account::TYPE_EQUITY]['total_balance'] + $currentProfit;

        return [
            'account_name' => '元入金',
            'sub_account_name' => '元入金',
            'amount' => abs($capitalBalance),
            'type' => $capitalBalance >= 0 ? JournalEntry::TYPE_CREDIT : JournalEntry::TYPE_DEBIT,
        ];
    }

    /**
     * @return 'debit'|'credit'
     */
    protected function balanceTypeToJournalEntryType(string $accountType, int $balance): string
    {
        $isDebitBalance = match ($accountType) {
            Account::TYPE_ASSET => $balance >= 0,
            Account::TYPE_LIABILITY => $balance < 0,
            default => $balance >= 0,
        };

        return $isDebitBalance ? JournalEntry::TYPE_DEBIT : JournalEntry::TYPE_CREDIT;
    }
}
